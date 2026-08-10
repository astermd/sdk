# Pinned to the minimum supported PHP version so local runs catch any accidental
# use of 8.5-only syntax. CI additionally runs the whole gate on 8.5.
FROM php:8.4-cli-alpine

RUN apk add --no-cache git unzip libzip-dev \
 && docker-php-ext-install zip \
 && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app
