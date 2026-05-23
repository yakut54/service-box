FROM php:8.2-fpm

# System packages
RUN apt-get update && apt-get install -y \
    git unzip libpq-dev libzip-dev libonig-dev postgresql-client \
    && docker-php-ext-install pdo pdo_pgsql pgsql bcmath mbstring \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Ensure directories exist
RUN mkdir -p bootstrap/cache storage/framework/sessions storage/framework/views storage/framework/cache storage/logs

# Copy composer files for caching
COPY composer.json composer.lock* ./

# First composer install (may fail due to artisan - ignore error)
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction || true

# Copy entire project
COPY . .

# Set permissions before second composer run
RUN chown -R www-data:www-data bootstrap storage

# Second composer install (clean)
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction

# Final permissions
RUN chown -R www-data:www-data storage bootstrap/cache

# PHP upload limits
RUN echo "upload_max_filesize=10M\npost_max_size=12M\nmax_execution_time=60" \
    > /usr/local/etc/php/conf.d/uploads.ini

CMD ["php-fpm"]
