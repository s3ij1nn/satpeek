FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
        git unzip curl bash icu-dev oniguruma-dev libzip-dev \
        libpng-dev libjpeg-turbo-dev freetype-dev \
        postgresql-dev postgresql-client \
        gmp-dev \
        autoconf g++ make linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        bcmath pdo pdo_pgsql mbstring intl zip gd opcache pcntl gmp \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del autoconf g++ make linux-headers \
    && rm -rf /tmp/* /var/cache/apk/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
