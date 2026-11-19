FROM php:8.1-cli-alpine

USER root

RUN apk add --no-cache git gcc make autoconf libc-dev bash tzdata linux-headers libzip-dev icu-dev libsodium-dev libpng-dev libxslt-dev
RUN docker-php-ext-configure intl && docker-php-ext-configure zip && docker-php-ext-install zip intl sodium bcmath gd xsl

RUN echo "memory_limit = 512M" > /usr/local/etc/php/conf.d/memory-limit.ini

# Set locale.
ENV LANG pl_PL.UTF-8
ENV LC_ALL pl_PL.UTF-8

RUN cp /usr/share/zoneinfo/Europe/Warsaw /etc/localtime
RUN echo "Europe/Warsaw" > /etc/timezone
RUN apk del tzdata

# Install Composer.
RUN mkdir -p /usr/bin && wget -q -O /usr/bin/composer https://getcomposer.org/download/2.9.5/composer.phar && chmod +x /usr/bin/composer

RUN addgroup comfino
RUN adduser -S -u 1000 comfino -G comfino

ARG WITH_XDEBUG=false

RUN if [ "$WITH_XDEBUG" = "true" ]; then \
        apk add --no-cache $PHPIZE_DEPS \
        && pecl install xdebug \
        && docker-php-ext-enable xdebug \
        && echo "xdebug.mode=off" > /usr/local/etc/php/conf.d/xdebug.ini; \
    fi

USER comfino
