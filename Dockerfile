FROM php:8.4-cli-alpine

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer

RUN apk add --no-cache \
        bash \
        git \
        unzip \
        zip \
        curl

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app
