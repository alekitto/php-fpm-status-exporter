# PHP FPM pool status exporter

This application is meant to connect to a configured PHP fpm pool, read current state and expose these metrics on CloudWatch.

## Configuration
Application configuration is provided using ENV variables:

| Variable        | Example                                       | Meaning                                                                   |
|-----------------|-----------------------------------------------|---------------------------------------------------------------------------|
| APP_ENV         | local                                         | Runtime environment.                                                      | 
| CLUSTER_NAME    | AWS-EC2                                       | The cluster name used in CW metrics (used only outside ECS)               | 
| MEMORY_PER_TASK | 60                                            | The memory used by each task, used to compute the maximum process count   | 
| SENTRY_DSN      | https://foobar@123456.ingest.sentry.io/789456 | Specify a Sentry.io hub dsn to push notifications there on error/warnings | 
| TASK_NAME       | PHP-FPM                                       | The task name used in CW metrics (used only outside ECS)                  | 

## Usage

```bash
Usage:
  php-fpm-status-exporter.phar [options] [--] <socket> <path>

Arguments:
  socket                         The address of php-fpm socket (use unix:// prefix to use a unix socket)
  path                           The status page address

Options:
  -s, --namespace=NAMESPACE      Metric namespace [default: "APP"]
  -d, --dry-run                  Do not push metrics, print them out instead
  -r, --region=REGION            AWS Region
  -p, --profile=PROFILE          AWS Credentials Profile
  -x, --resolution[=RESOLUTION]  Define metrics resolution [default: "60"]
  -h, --help                     Display help for the given command. When no command is given display help for the run command
  -q, --quiet                    Do not output any message
  -V, --version                  Display this application version
      --ansi|--no-ansi           Force (or disable --no-ansi) ANSI output
  -n, --no-interaction           Do not ask any interactive question
  -v|vv|vvv, --verbose           Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug
```

For example:
```bash
php-fpm-status-exporter.phar -r us-east-1 'php:9000' '/.well-known/php-fpm-status' -x 5
```

### Max children computation

`compute-max-children` subcommand can be used to calculate the maximum children count that will fit in memory.  
Total memory will be retrieved by ECS metadata or checking `/proc/meminfo` where available.

```bash
php-fpm-status-exporter.phar compute-max-children
```
