# ==========================================================================
#  Stage 1: Build frontend assets (D171)
#  Node.js build stage compiles Vue SPA + Tailwind CSS via Vite.
#  Only public/assets/ is carried forward — no Node.js in final image.
# ==========================================================================
FROM node:24-bookworm-slim AS frontend

WORKDIR /app

# Copy dependency manifests first for layer caching
COPY package.json package-lock.json ./
RUN npm ci

# Copy source files needed for the Vite build
COPY vite.config.js ./
COPY resources/ resources/

# Build production assets
RUN npm run build

# ==========================================================================
#  Stage 2: PHP application (D69, D165)
#  FrankenPHP + Laravel Octane — serves API, SSE, and SPA static files.
# ==========================================================================
FROM dunglas/frankenphp:php8.5-bookworm

# Add PostgreSQL 18 apt repo for version-matched pg_dump
RUN apt-get update && apt-get install -y curl ca-certificates gnupg \
    && curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc | gpg --dearmor -o /usr/share/keyrings/postgresql.gpg \
    && echo "deb [signed-by=/usr/share/keyrings/postgresql.gpg] http://apt.postgresql.org/pub/repos/apt bookworm-pgdg main" > /etc/apt/sources.list.d/pgdg.list

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libpq-dev \
    libzip-dev \
    libicu-dev \
    postgresql-client-18 \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions (bundled)
# Install PHP extensions (opcache is statically compiled in FrankenPHP php8.5)
RUN docker-php-ext-install pdo_pgsql pgsql zip intl pcntl

# Install Redis PHP extension
RUN pecl install redis \
    && docker-php-ext-enable redis

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

# Copy compiled frontend assets from build stage (D171)
# Positioned after COPY . . to overwrite any stale public/assets/ from source context
COPY --from=frontend /app/public/assets public/assets

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
