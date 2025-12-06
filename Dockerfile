FROM php:8.2-fpm-alpine

# Install system dependencies including build tools for pecl
RUN apk update && apk add --no-cache \
    git \
    curl \
    zip \
    unzip \
    libzip-dev \
    oniguruma-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    icu-dev \
    # Build tools for pecl extensions
    autoconf \
    g++ \
    make \
    pkgconfig \
    linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
    pdo_mysql \
    mbstring \
    zip \
    exif \
    pcntl \
    gd \
    intl

# Install Redis extension using pecl
RUN pecl install redis && docker-php-ext-enable redis

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Clean up build tools to reduce image size
RUN apk del autoconf g++ make pkgconfig linux-headers

# Create a non-root user
RUN addgroup -g 1000 -S www && \
    adduser -u 1000 -S www -G www

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . /var/www/html

# Set proper permissions (only if directories exist)
RUN if [ -d "/var/www/html/storage" ]; then \
        chown -R www:www /var/www/html/storage && \
        chmod -R 755 /var/www/html/storage; \
    fi && \
    if [ -d "/var/www/html/bootstrap/cache" ]; then \
        chown -R www:www /var/www/html/bootstrap/cache && \
        chmod -R 755 /var/www/html/bootstrap/cache; \
    fi

# Switch to non-root user
USER www

CMD ["php-fpm"]