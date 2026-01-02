# Tether Worker Node Dockerfile
# THR-172: Provision worker nodes
#
# Base: PHP 8.3 CLI Alpine (no Nginx, no FPM)
# Purpose: Laravel Horizon queue processing

FROM php:8.3-cli-alpine

LABEL maintainer="Tether Team"
LABEL description="Tether Worker Node with Horizon"

# Install system dependencies
RUN apk add --no-cache \
    supervisor \
    git \
    curl \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libxml2-dev \
    libzip-dev \
    postgresql-dev \
    icu-dev \
    oniguruma-dev \
    && rm -rf /var/cache/apk/*

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_pgsql \
        gd \
        mbstring \
        xml \
        zip \
        intl \
        bcmath \
        pcntl \
        opcache

# Install Redis extension
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Configure PHP
COPY php.ini /usr/local/etc/php/conf.d/custom.ini

# Configure Supervisor
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Create app directory
WORKDIR /var/www/html

# Copy application
COPY --chown=www-data:www-data . /var/www/html

# Install dependencies (production)
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Set permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Health check via Horizon status
HEALTHCHECK --interval=30s --timeout=10s --start-period=10s --retries=3 \
    CMD php artisan horizon:status || exit 1

# Start supervisor
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
