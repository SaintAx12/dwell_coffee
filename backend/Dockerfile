FROM php:8.3-cli

RUN apt-get update && apt-get install -y \
    git unzip libpq-dev libzip-dev \
    && docker-php-ext-install pdo pdo_pgsql zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction

EXPOSE 10000

CMD sh -c "php artisan config:clear && php artisan migrate --force && php artisan tinker --execute='if(\App\Models\User::count() === 0) { \Artisan::call(\"db:seed\", [\"--force\" => true]); }' && php artisan serve --host=0.0.0.0 --port=\${PORT:-10000}"