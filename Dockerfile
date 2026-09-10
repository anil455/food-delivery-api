FROM php:8.4-fpm

# System dependencies
RUN apt-get update && apt-get install -y \
    nginx \
    supervisor \
    git \
    unzip \
    libzip-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libonig-dev \
    libicu-dev \
    && rm -rf /var/lib/apt/lists/*

# GD configuration
RUN docker-php-ext-configure gd \
    --with-freetype \
    --with-jpeg

# PHP extensions required by Laravel/packages
RUN docker-php-ext-install \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip \
    intl \
    opcache

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy Laravel project
COPY . .

# Install Composer dependencies
RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --prefer-dist

# Permissions
RUN chown -R www-data:www-data storage bootstrap/cache

# Nginx
COPY docker/nginx.conf /etc/nginx/sites-available/default

# Supervisor
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Storage link
RUN php artisan storage:link || true

EXPOSE 10000

CMD ["/usr/bin/supervisord", "-n"]