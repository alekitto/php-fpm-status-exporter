<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'compute-max-children')]
class ComputeMaxChildren extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(sprintf("%u", self::computeMaxChildren()));

        return Command::SUCCESS;
    }

    public static function computeMaxChildren(): int {
        $metadata = null;
        $metadataEndpoint = getenv('ECS_CONTAINER_METADATA_URI_V4');
        if (false !== $metadataEndpoint) {
            try {
                $metadata = json_decode(file_get_contents($metadataEndpoint . '/task'), true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable $e) {
                // @ignoreException
            }

            $memoryMb = $metadata['Limits']['Memory'];
        } else {
            $memoryMb = self::readMeminfo() / 1024 / 1024;
        }

        if (null === $memoryMb) {
            return 8;
        }

        $memoryPerTask = (int) (getenv('MEMORY_PER_TASK') ?: '60');

        return (int) floor($memoryMb / $memoryPerTask);
    }

    private static function readMeminfo(): int|null
    {
        $meminfo = file_get_contents('/proc/meminfo');
        if ($meminfo) {
            $memTotal = null;
            foreach (preg_split("/\n/", $meminfo, -1, PREG_SPLIT_NO_EMPTY) as $buf) {
                if (preg_match('/^MemTotal:\s+(\d+)\s*kB/i', $buf, $matches)) {
                    $memTotal = $matches[1] * 1024;
                    break;
                }
            }

            return (int)($memTotal * 0.8);
        }

        // TODO: win32
        return null;
    }
}
