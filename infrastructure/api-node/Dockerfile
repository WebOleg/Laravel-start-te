# Tether API Node Dockerfile
# THR-171: Provision API nodes
#
# Base: PHP 8.3 FPM Alpine
# Extensions: pdo_pgsql redis gd mbstring xml zip

FROM php:8.3-fpm-alpine

LABEL maintainer="Tether Team"
LABEL description="Tether API Node"

# Install system dependencies
RUN apk add --no-cache \
    nginx \
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
COPY php-fpm.conf /usr/local/etc/php-fpm.d/www.conf

# Configure Nginx
COPY nginx-site.conf /etc/nginx/http.d/default.conf

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

# Expose port
EXPOSE 9000

# Health check
HEALTHCHECK --interval=30s --timeout=5s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/health || exit 1

# Start supervisor
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
