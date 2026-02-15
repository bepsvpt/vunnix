FROM dunglas/frankenphp:php8.4-bookworm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libpq-dev \
    libzip-dev \
    libicu-dev \
    && docker-php-ext-install \
    pdo_pgsql \
    pgsql \
    zip \
    intl \
    pcntl \
    opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy composer files first for better layer caching
COPY composer.json composer.lock ./

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

# Copy application code
COPY . .

# Run post-install scripts
RUN composer dump-autoload --optimize \
    && php artisan package:discover --ansi

# Create storage directories and set permissions
RUN mkdir -p \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Configure PHP for production
RUN cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini

# Expose ports (Caddy HTTP/HTTPS + Octane)
EXPOSE 80 443

# Default entrypoint runs FrankenPHP with Octane
CMD ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=80"]
