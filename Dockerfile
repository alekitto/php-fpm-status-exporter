# syntax=docker/dockerfile:1.4
FROM public.ecr.aws/bitcompat/php-fpm:8.2

ENV APP_ENV=production

WORKDIR /app
COPY . .

RUN composer install --no-interaction --prefer-dist --optimize-autoloader && \
    composer clear-cache

ENTRYPOINT ["php", "app.php"]
