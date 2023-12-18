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
    public function __construct(
        private readonly string $metricNamespace,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('socket', InputArgument::REQUIRED, 'The address of php-fpm socket (use unix:// prefix to use a unix socket)')
            ->addArgument('path', InputArgument::REQUIRED, 'The status page address')
            ->addOption('dry-run', 'd', InputOption::VALUE_NONE, 'Do not push metrics, print them out instead')
            ->addOption('region', 'r', InputOption::VALUE_REQUIRED, 'AWS Region')
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'AWS Credentials Profile')
            ->addOption('hi-res', null, InputOption::VALUE_NONE, 'Enable Hi-resolution metrics');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        gc_enable();

        $io = new SymfonyStyle($input, $output);

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
        $resolution = $input->getOption('hi-res') ? 5 : 60;
        $sleepTime = $resolution;
        $responseBody = null;

        while (!$stop) {
            try {
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
                            $value['Value'], ];
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
                            'Value' => $value[ 'Value' ],
                            'Unit' => $value[ 'Unit' ],
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
                    ];
                }

                $cloudwatch->putMetricData([
                    'Namespace' => $this->metricNamespace,
                    'StorageResolution' => $resolution,
                    'MetricData' => $metricsData,
                ]);

                $sleepTime = $resolution;
            } catch (JsonException $e) {
                $sleepTime = 2;

                $this->logger->log(LogLevel::WARNING, sprintf(
                    'Error decoding php fpm status: %s.',
                    $e->getMessage()
                ), [
                    'response_body' => (string) $responseBody,
                ]);
            } catch (Throwable $e) {
                $this->logger->log(LogLevel::WARNING, sprintf(
                    'Error while retrieving php fpm status: %s',
                    $e->getMessage()
                ));
            } finally {
                sleep($sleepTime);
                pcntl_signal_dispatch();

                unset($socket);
                gc_collect_cycles();
                gc_mem_caches();
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
