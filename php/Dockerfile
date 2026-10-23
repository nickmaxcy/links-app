FROM php:8.4-fpm

# System libraries needed to build the PHP extensions below.
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    libsodium-dev \
    libonig-dev \
    && docker-php-ext-install -j$(nproc) pdo_mysql curl sodium mbstring opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html
