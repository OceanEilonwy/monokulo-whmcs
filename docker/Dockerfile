# PHP for this gateway's tests: the stand-in WHMCS (tests/whmcs) under PHP's
# built-in web server, and PHPUnit. The PHP version is a build argument: CI
# builds 8.1, 8.2, 8.3 and 8.4.
ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli

RUN apt-get update \
 && apt-get install -y --no-install-recommends libzip-dev unzip \
 && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql zip \
 && (pecl install pcov && docker-php-ext-enable pcov || echo "pcov is not available for this PHP: no coverage")

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN printf 'display_errors = Off\nlog_errors = On\nerror_reporting = E_ALL\nmemory_limit = 512M\npcov.directory = /repo/src\n' > /usr/local/etc/php/conf.d/tests.ini
