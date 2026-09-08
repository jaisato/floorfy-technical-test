# syntax=docker/dockerfile:1
#
# Build context: ./app (see docker-compose.yml). Produces one image used by the
# php-fpm (API), worker and migrate services. The image is self-contained: the
# application code and its production dependencies are copied in at build time,
# so `docker compose up --build` works on a clean clone with nothing installed
# on the host.

# ---- 1. dependencies -------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app
COPY symfony/composer.json symfony/composer.lock symfony/symfony.lock ./

# The composer image lacks ext-amqp/ext-intl, which the runtime stage provides;
# the autoloader is generated in the runtime stage once the sources are present.
RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache composer install \
        --no-dev --no-scripts --no-autoloader --no-interaction --no-progress \
        --prefer-dist --ignore-platform-reqs

# ---- 2. runtime ------------------------------------------------------------
FROM php:8.4-fpm-bookworm AS runtime

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ffmpeg libicu-dev libzip-dev librabbitmq-dev libssl-dev $PHPIZE_DEPS; \
    pecl install amqp; \
    docker-php-ext-enable amqp; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql intl zip opcache; \
    apt-get purge -y --auto-remove $PHPIZE_DEPS; \
    rm -rf /var/lib/apt/lists/* /tmp/pear

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY symfony/ ./
COPY docker/entrypoint-php.sh docker/entrypoint-worker.sh /usr/local/bin/

# The image is production-only: APP_ENV is fixed here so the container compiled
# below is the one that runs, whatever the runtime .env files say.
ENV APP_ENV=prod \
    APP_DEBUG=0 \
    COMPOSER_ALLOW_SUPERUSER=1

RUN set -eux; \
    chmod +x /usr/local/bin/entrypoint-php.sh /usr/local/bin/entrypoint-worker.sh; \
    composer dump-autoload --no-dev --classmap-authoritative --no-interaction; \
    php bin/console cache:warmup; \
    mkdir -p var/log var/work public/videos; \
    chown -R www-data:www-data var public/videos

# Named volumes mounted on var/work and public/videos inherit these permissions
# when they are first created, so no chown is needed at container start.
USER www-data

# The same checks GET /health/ready runs. Declared here as well as in compose so
# that the image is self-describing wherever it is deployed.
HEALTHCHECK --interval=15s --timeout=10s --start-period=30s --retries=5 \
    CMD ["php", "bin/console", "app:health:ready"]

ENTRYPOINT ["entrypoint-php.sh"]
