# PHP FPM pool status exporter

This application is meant to connect to a configured PHP fpm pool, read current state and expose these metrics on CloudWatch.


## Configuration
Application configuration is provided using ENV variables:

| Variable          | Example                                       | Meaning                                                                   |
|-------------------|-----------------------------------------------|---------------------------------------------------------------------------|
| APP_ENV           | local                                         | Runtime environment.                                                      | 
| METRIC_NAMESPACE  | API                                           | CloudWatch metrics namespace.                                             | 
| SENTRY_DSN        | https://foobar@123456.ingest.sentry.io/789456 | Specify a Sentry.io hub dsn to push notifications there on error/warnings | 

## Usage

```bash
Usage:
  php app.php [options] [--] <socket> <path>

Arguments:
  socket                 The address of php-fpm socket (use unix:// prefix to use a unix socket)
  path                   The status page address

Options:
  -d, --dry-run          Do not push metrics, print them out instead
  -r, --region=REGION    AWS Region
  -p, --profile=PROFILE  AWS Credentials Profile
      --hi-res           Enable Hi-resolution metrics
  -h, --help             Display help for the given command. When no command is given display help for the status-agent command
  -q, --quiet            Do not output any message
  -V, --version          Display this application version
      --ansi|--no-ansi   Force (or disable --no-ansi) ANSI output
  -n, --no-interaction   Do not ask any interactive question
  -v|vv|vvv, --verbose   Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug
```

For example:
```bash
php app.php -r us-east-1 'php:9000' '/.well-known/php-fpm-status' --hi-res
```
