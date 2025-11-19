FROM php:8.2-cli-alpine

RUN apk add --no-cache git curl libzip-dev oniguruma-dev \
    && docker-php-ext-install pdo_mysql mbstring \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer

WORKDIR /app
COPY . /app

RUN composer install --no-progress --no-dev --prefer-dist --optimize-autoloader || true

CMD ["php", "-v"]