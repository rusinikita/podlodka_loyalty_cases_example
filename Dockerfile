FROM composer:2.8 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs
COPY app ./app
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

FROM php:8.4-fpm-alpine
RUN apk add --no-cache nginx supervisor librdkafka libpq \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS librdkafka-dev postgresql-dev linux-headers \
    && docker-php-ext-install pcntl pdo_pgsql \
    && pecl install rdkafka \
    && docker-php-ext-enable rdkafka \
    && apk del .build-deps
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-loyalty.conf
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY supervisord.conf /etc/supervisord.conf
WORKDIR /app
COPY --from=vendor /app/vendor ./vendor
COPY artisan composer.json ./
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY public ./public
COPY routes ./routes
COPY storage ./storage
RUN chown -R www-data:www-data storage bootstrap/cache
EXPOSE 8080
ENTRYPOINT ["supervisord", "-c", "/etc/supervisord.conf"]
