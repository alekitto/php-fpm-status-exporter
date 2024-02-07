<?php

declare(strict_types=1);

namespace App;

use Aws\CloudWatch\CloudWatchClient;
use Aws\Ecs\EcsClient;
use hollodotme\FastCGI\Encoders\NameValuePairEncoder;
use hollodotme\FastCGI\Encoders\PacketEncoder;
use hollodotme\FastCGI\Interfaces\ConfiguresSocketConnection;
use hollodotme\FastCGI\Requests\GetRequest;
use hollodotme\FastCGI\SocketConnections\NetworkSocket;
use hollodotme\FastCGI\SocketConnections\UnixDomainSocket;
use hollodotme\FastCGI\Sockets\Socket;
use hollodotme\FastCGI\Sockets\SocketId;
use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'run')]
class ExportFpmStats extends Command
{
    public function __construct(private readonly LoggerInterface $logger)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('socket', InputArgument::REQUIRED, 'The address of php-fpm socket (use unix:// prefix to use a unix socket)')
            ->addArgument('path', InputArgument::REQUIRED, 'The status page address')
            ->addOption('namespace', 's', InputOption::VALUE_REQUIRED, 'Metric namespace', 'APP')
            ->addOption('dry-run', 'd', InputOption::VALUE_NONE, 'Do not push metrics, print them out instead')
            ->addOption('region', 'r', InputOption::VALUE_REQUIRED, 'AWS Region')
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'AWS Credentials Profile')
            ->addOption('resolution', 'x', InputOption::VALUE_OPTIONAL, 'Define metrics resolution', '60');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        gc_enable();

        $io = new SymfonyStyle($input, $output);

        $namespace = $input->getOption('namespace');
        if (empty($namespace)) {
            $io->error('No namespace defined for metrics');
            return self::INVALID;
        }

        $maxChildren = ComputeMaxChildren::computeMaxChildren();
        $socketConfiguration = $this->getSocketConfiguration($input);

        $stop = false;
        pcntl_async_signals(true);
        pcntl_signal(SIGINT, static function () use (&$stop): void {
            $stop = true;
        });

        $request = new GetRequest($input->getArgument('path'), '');
        $request->setCustomVar('SCRIPT_NAME', $input->getArgument('path'));
        $request->setCustomVar('QUERY_STRING', 'json&full');

        $region = $input->getOption('region') ?: getenv('AWS_REGION') ?: getenv('AWS_DEFAULT_REGION');
        $profile = $input->getOption('profile');
        $options = [];

        if (!empty($region)) {
            $options['region'] = $region;
        }

        if (null !== $profile) {
            $options['profile'] = $profile;
        }

        $metadata = null;
        $metadataEndpoint = getenv('ECS_CONTAINER_METADATA_URI_V4');

        if (false !== $metadataEndpoint) {
            try {
                $metadata = json_decode(file_get_contents($metadataEndpoint . '/task'), true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                // @ignoreException
            }
        }

        if (null === $metadata) {
            $cluster = getenv('CLUSTER_NAME') ?: 'AWS-EC2';
            $taskName = getenv('TASK_NAME') ?: 'PHP-FPM';
            $taskArn = null;
        } else {
            $cluster = $metadata['Cluster'];
            $taskArn = $metadata['TaskARN'];
            $ecs = new EcsClient($options + ['version' => '2014-11-13']);
            $task = $ecs->describeTasks([
                'cluster' => $cluster,
                'tasks' => [$taskArn],
            ])->get('tasks')[0];

            $group = $task['group'];
            if (!str_contains($group, 'service:')) {
                $taskName = 'Generic';
            } else {
                $taskName = substr($group, 8);
            }
        }

        $cloudwatch = new CloudWatchClient($options + ['version' => '2010-08-01']);
        $resolutionOption = $input->getOption('resolution');
        if (! is_numeric($resolutionOption)) {
            $io->warning('Non-numeric resolution, defaulting to 60 secs');
            $resolutionOption = '60';
        }

        $resolutionOption = (int) $resolutionOption;
        if ($resolutionOption < 1) {
            $io->warning('Invalid resolution, setting to 1 sec');
            $resolutionOption = 1;
        }

        if ($resolutionOption > 60 && ($rem = $resolutionOption % 60) !== 0) {
            $resolutionOption -= $rem;
            $io->warning('Invalid resolution: should be under 60 seconds or multiple of 60 seconds. Setting to ' . $resolutionOption);
        }

        $resolution = $resolutionOption < 60 ? 1 : 60;
        $responseBody = null;

        while (!$stop) {
            $start = (int) (microtime(true) * 1000);

            try {
                $sleepTime = $resolutionOption * 1000;

                $socket = self::createSocket($socketConfiguration);
                $socket->sendRequest($request);

                $response = $socket->fetchResponse(5000);
                $responseBody = $response->getBody();

                $status = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);

                unset($socket);
                gc_collect_cycles();
                gc_mem_caches();

                $stats = [
                    'ListenQueue' => ['Unit' => 'None', 'Value' => $status['listen queue']],
                    'ListenQueueLen' => ['Unit' => 'None', 'Value' => $status['listen queue len']],
                    'MaxProcesses' => ['Unit' => 'None', 'Value' => $maxChildren],
                    'IdleProcesses' => ['Unit' => 'None', 'Value' => $status['idle processes']],
                    'ActiveProcesses' => ['Unit' => 'None', 'Value' => $status['active processes']],
                ];


                $clusterName = ($slash = strrpos($cluster, '/')) !== false ?
                    substr($cluster, $slash + 1) :
                    $cluster;


                if ($input->getOption('dry-run')) {
                    $rows = [];
                    foreach ($stats as $name => $value) {
                        $rows[] = [
                            $clusterName,
                            $taskName,
                            $name,
                            $value['Value'],
                        ];
                    }

                    $io->table(['ClusterName', 'ServiceName', 'MetricsName', 'Value'], $rows);

                    continue;
                }

                $time = time();
                $metricsData = [];
                foreach ($stats as $name => $value) {
                    if ($taskArn !== null) {
                        $metricsData[] = [
                            'MetricName' => $name,
                            'Timestamp' => $time,
                            'Dimensions' => [
                                [
                                    'Name' => 'ClusterName',
                                    'Value' => $clusterName,

                                ],
                                [
                                    'Name' => 'ServiceName',
                                    'Value' => $taskName,
                                ],
                                [
                                    'Name' => 'Task',
                                    'Value' => false !== strrpos($taskArn, '/') ? substr($taskArn, strrpos($taskArn, '/') + 1) : $taskArn,
                                ],
                            ],
                            'Value' => $value['Value'],
                            'Unit' => $value['Unit'],
                            'StorageResolution' => $resolution,
                        ];
                    }

                    $metricsData[] = [
                        'MetricName' => $name,
                        'Timestamp' => $time,
                        'Dimensions' => [
                            [
                                'Name' => 'ClusterName',
                                'Value' => $clusterName,

                            ],
                            [
                                'Name' => 'ServiceName',
                                'Value' => $taskName,
                            ],
                        ],
                        'Value' => $value['Value'],
                        'Unit' => $value['Unit'],
                        'StorageResolution' => $resolution,
                    ];
                }

                $cloudwatch->putMetricData([
                    'Namespace' => $namespace,
                    'MetricData' => $metricsData,
                ]);
            } catch (JsonException $e) {
                $sleepTime = 2_000;

                $this->logger->log(LogLevel::WARNING, sprintf(
                    'Error decoding php fpm status: %s.',
                    $e->getMessage()
                ), [
                    'response_body' => (string) $responseBody,
                ]);
            } catch (Throwable $e) {
                $sleepTime = 2_000;

                $this->logger->log(LogLevel::WARNING, sprintf(
                    'Error while retrieving php fpm status: %s',
                    $e->getMessage()
                ));
            } finally {
                pcntl_signal_dispatch();

                unset($socket);
                gc_collect_cycles();
                gc_mem_caches();

                $elapsedTime = (((int) (microtime(true) * 1000)) - $start);
                $time = $sleepTime - $elapsedTime;
                if ($time < 0) {
                    $time = 1;
                }

                $seconds = (int) ($time / 1000);
                $ms = $time % 1000;
                time_nanosleep($seconds, $ms * 1_000_000);
            }
        }

        return Command::SUCCESS;
    }

    private function getSocketConfiguration(InputInterface $input): ConfiguresSocketConnection
    {
        $socketPath = $input->getArgument('socket');
        if ('unix' === parse_url($socketPath, PHP_URL_SCHEME)) {
            $socketConfiguration = new UnixDomainSocket(parse_url($socketPath, PHP_URL_PATH));
        } else {
            $host = parse_url($socketPath, PHP_URL_HOST);
            $port = (int) (parse_url($socketPath, PHP_URL_PORT) ?: 9000);
            $socketConfiguration = new NetworkSocket($host, $port);
        }

        return $socketConfiguration;
    }

    private static function createSocket(ConfiguresSocketConnection $config): Socket
    {
        return new Socket(SocketId::new(), $config, new PacketEncoder(), new NameValuePairEncoder());
    }
}
