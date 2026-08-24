FROM php:8.2-fpm

# System packages
# libpng/libjpeg/libwebp-dev + gd — серверное сжатие фото в чате (см.
# PLAN-CHAT.md §5): что бы ни прислал клиент, сервер сам ужимает до 100 КБ,
# а не отклоняет с ошибкой "файл слишком большой".
# pcntl — без него `php artisan reverb:start` падает с "Undefined constant
# SIGINT" (сигналы завершения процесса не работают без этого расширения,
# по умолчанию его нет в образе php:8.2-fpm).
RUN apt-get update && apt-get install -y \
    git unzip libpq-dev libzip-dev libonig-dev postgresql-client \
    libpng-dev libjpeg-dev libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install pdo pdo_pgsql pgsql bcmath mbstring gd pcntl \
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

# PHP upload limits — с запасом под фото прямо с камеры (в т.ч. с
# современных телефонов с большим разрешением сенсора), сервер всё равно
# сжимает результат до 100 КБ сам, лимит здесь — просто разумный потолок
# на входе, не то, что видит пользователь.
RUN echo "upload_max_filesize=25M\npost_max_size=30M\nmax_execution_time=60" \
    > /usr/local/etc/php/conf.d/uploads.ini

CMD ["php-fpm"]
