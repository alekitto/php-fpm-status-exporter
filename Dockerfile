# syntax=docker/dockerfile:1.4
FROM public.ecr.aws/bitcompat/php-fpm:8.2

WORKDIR /app
COPY . .

RUN composer install --no-interaction --prefer-dist --optimize-autoloader && \
    composer clear-cache

CMD ["php", "bin/app.php"]
