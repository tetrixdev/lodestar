# =============================================================================
# PRODUCTION DOCKERFILE
# slim-docker-laravel-setup v0.1.0
# =============================================================================
FROM php:8.4-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    unzip \
    git \
    curl \
    ca-certificates \
    locales \
    supervisor \
    gnupg \
    && rm -rf /var/lib/apt/lists/*

# Install PostgreSQL 17 client (for pg_dump/pg_restore)
RUN curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc | gpg --dearmor -o /usr/share/keyrings/postgresql-keyring.gpg \
    && echo "deb [signed-by=/usr/share/keyrings/postgresql-keyring.gpg] https://apt.postgresql.org/pub/repos/apt $(. /etc/os-release && echo "$VERSION_CODENAME")-pgdg main" > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update \
    && apt-get install -y postgresql-client-17 \
    && rm -rf /var/lib/apt/lists/*

# Configure UTF-8 locale
RUN sed -i '/en_US.UTF-8/s/^# //g' /etc/locale.gen && \
    locale-gen en_US.UTF-8
ENV LANG=en_US.UTF-8
ENV LC_ALL=en_US.UTF-8

# Install PHP extensions
# pcntl: required by long-running console servers (e.g. Laravel Reverb) for signal handling
RUN docker-php-ext-install pdo pdo_pgsql zip pcntl

# Install Redis PHP extension
RUN pecl install -o -f redis \
    && rm -rf /tmp/pear \
    && docker-php-ext-enable redis

# Install Composer (with signature verification)
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
    && php -r "if (hash_file('sha384', 'composer-setup.php') === trim(file_get_contents('https://composer.github.io/installer.sig'))) { echo 'Installer verified'.PHP_EOL; } else { echo 'Installer corrupt'.PHP_EOL; unlink('composer-setup.php'); exit(1); }" \
    && php composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && php -r "unlink('composer-setup.php');"

# Create Laravel directories with proper structure
RUN mkdir -p /var/www/storage/framework/{sessions,views,cache} \
    && mkdir -p /var/www/storage/logs \
    && mkdir -p /var/www/bootstrap/cache

# Create supervisor log directory
RUN mkdir -p /var/log/supervisor

# Set working directory
WORKDIR /var/www

# =============================================================================
# PRODUCTION BUILD STEPS
# =============================================================================

# Copy Laravel application
COPY www/ /var/www/

# Install Composer dependencies (production optimized)
RUN composer install --no-dev --optimize-autoloader --no-interaction --verbose

# Frontend assets are prebuilt once in CI and arrive via "COPY www/" above as
# public/build/ — the same files the nginx image serves. Do not build them
# here: a second build yields different Tailwind hashes than the nginx image.

# Create storage symlink
RUN php artisan storage:link --no-interaction

# Copy PHP configuration
COPY docker-laravel/shared/php/local.ini /usr/local/etc/php/conf.d/local.ini

# Copy supervisor configuration
COPY docker-laravel/shared/supervisor/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker-laravel/production/php/supervisor/ /etc/supervisor/conf.d/

# Copy production entrypoint
COPY docker-laravel/production/php/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Set proper permissions
RUN chown -R www-data:www-data /var/www

# Expose port 9000 for PHP-FPM
EXPOSE 9000

# Use production entrypoint
ENTRYPOINT ["/entrypoint.sh"]
