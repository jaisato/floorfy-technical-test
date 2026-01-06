FROM php:8.4-fpm-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    $PHPIZE_DEPS \
    git unzip ffmpeg \
    libzip-dev libicu-dev \
    librabbitmq-dev libssl-dev \
 && pecl install amqp \
 && docker-php-ext-enable amqp \
 && docker-php-ext-install pdo_mysql intl zip \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
