<?php

declare(strict_types=1);

namespace App\Logger;

use Psr\Log\LogLevel;
use Sentry\EventHint;
use Sentry\Severity;
use Sentry\State\HubInterface;
use Stringable;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;

class SentryConsoleLogger extends ConsoleLogger
{
    private array $verbosityLevelMap = [
        LogLevel::EMERGENCY => OutputInterface::VERBOSITY_NORMAL,
        LogLevel::ALERT => OutputInterface::VERBOSITY_NORMAL,
        LogLevel::CRITICAL => OutputInterface::VERBOSITY_NORMAL,
        LogLevel::ERROR => OutputInterface::VERBOSITY_NORMAL,
        LogLevel::WARNING => OutputInterface::VERBOSITY_NORMAL,
        LogLevel::NOTICE => OutputInterface::VERBOSITY_VERBOSE,
        LogLevel::INFO => OutputInterface::VERBOSITY_VERY_VERBOSE,
        LogLevel::DEBUG => OutputInterface::VERBOSITY_DEBUG,
    ];

    public function __construct(
        private readonly HubInterface $sentry,
        private readonly OutputInterface $output,
        array $verbosityLevelMap = [],
        array $formatLevelMap = []
    ) {
        parent::__construct($output, $verbosityLevelMap, $formatLevelMap);
        $this->verbosityLevelMap = $verbosityLevelMap + $this->verbosityLevelMap;
    }

    public function log($level, $message, array $context = []): void
    {
        parent::log($level, $message, $context);
        $output = $this->output;

        // capture if log level is >= command verbosity (default verbosity will log only >= WARNING)
        if ($output->getVerbosity() >= $this->verbosityLevelMap[$level]) {
            $this->sentry->captureMessage(
                $message,
                new Severity($level),
                EventHint::fromArray($context)
            );
        }
    }
}
