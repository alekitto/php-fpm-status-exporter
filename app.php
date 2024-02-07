#!/usr/bin/env php
<?php

declare(strict_types=1);

include __DIR__ . '/vendor/autoload.php';

use App\ComputeMaxChildren;
use App\ExportFpmStats;
use App\Logger\SentryConsoleLogger;
use Sentry\ErrorHandler;
use Sentry\SentrySdk;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

use function Sentry\init;

init([
    'dsn' => getenv('SENTRY_DSN'),
    'environment' => getenv('APP_ENV') ?: 'local',
]);

ErrorHandler::registerOnceErrorHandler();
ErrorHandler::registerOnceExceptionHandler();
ErrorHandler::registerOnceFatalErrorHandler();

$application = new Application($appName = 'status-agent');
$application->setAutoExit(true);
$application->setName($_SERVER['argv'][0]);

$output = new ConsoleOutput();
$logger = new SentryConsoleLogger(
    SentrySdk::getCurrentHub(),
    $output
);

$command = new ExportFpmStats($logger);
$application->add($command);
$application->setDefaultCommand($command->getName(), false);

$application->add(new ComputeMaxChildren());
$application->run(new ArgvInput(), $output);
