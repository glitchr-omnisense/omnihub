# PHP with Composer (and zlib, sodium, which the official image builds in): the harness installs
# this package and every omnihub/* provider at start.
FROM php:8.4-cli-alpine
RUN apk add --no-cache git unzip libzip-dev $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" zip \
    && apk del $PHPIZE_DEPS
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_CACHE_DIR=/harness/.cache
WORKDIR /harness
ENTRYPOINT ["/omnihub/core/docker/entrypoint.sh"]
CMD ["bare"]
