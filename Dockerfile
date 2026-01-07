FROM php:8.3-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libpq-dev \
    libzip-dev \
    zip \
    unzip

# Clear cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_pgsql pgsql mbstring exif pcntl bcmath gd zip

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Install Xdebug conditionally for development (opt-in via build arg)
ARG INSTALL_XDEBUG=false
RUN if [ "$INSTALL_XDEBUG" = "true" ]; then \
    pecl install xdebug && docker-php-ext-enable xdebug; \
    fi

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www

# Copy Xdebug configuration if Xdebug is installed
COPY docker/php/xdebug.ini* /tmp/
RUN if [ "$INSTALL_XDEBUG" = "true" ] && [ -f /tmp/xdebug.ini ]; then \
    cp /tmp/xdebug.ini /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini; \
    fi

# Copy existing application directory
COPY . .

# Install dependencies
RUN rm -rf vendor
RUN composer install --no-interaction --no-dev --optimize-autoloader
#RUN composer install -vvv --no-interaction --no-dev --optimize-autoloader

# Set permissions
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]
