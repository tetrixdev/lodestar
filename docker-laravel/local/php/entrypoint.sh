#!/bin/bash
set -e

echo "=== slim-docker-laravel-setup v0.1.0 ==="
echo "Starting local development environment..."

# =============================================================================
# PERMISSIONS SETUP
# =============================================================================

# Set group ownership to www-data for shared access
chgrp -R www-data /var/www/storage /var/www/bootstrap/cache 2>/dev/null || true

# Set group write permissions (664 for files, 775 for directories)
find /var/www/storage -type d -exec chmod 775 {} \; 2>/dev/null || true
find /var/www/storage -type f -exec chmod 664 {} \; 2>/dev/null || true
find /var/www/bootstrap/cache -type d -exec chmod 775 {} \; 2>/dev/null || true
find /var/www/bootstrap/cache -type f -exec chmod 664 {} \; 2>/dev/null || true

# The public/ directory itself must be group-writable so the Vite dev server
# (running as www-data) can write its `public/hot` marker file. On Docker
# Desktop bind-mount UID translation hides this; on a native Linux host the
# bind-mounted dir keeps the host UID and www-data otherwise cannot write it.
chgrp www-data /var/www/public 2>/dev/null || true
chmod 775 /var/www/public 2>/dev/null || true

# =============================================================================
# LARAVEL SETUP
# =============================================================================

cd /var/www

# Install composer dependencies
echo "Installing composer dependencies..."
composer install

# Generate Laravel application key if not set
if [ -f ".env" ] && ! grep -q "^APP_KEY=.\+" .env; then
    echo "Generating Laravel application key..."
    php artisan key:generate --no-interaction
fi

# Create storage symlink if it doesn't exist
if [ ! -L "public/storage" ]; then
    echo "Creating storage symlink..."
    php artisan storage:link --no-interaction
fi

# Install npm dependencies
if [ -f "package.json" ]; then
    echo "Installing npm dependencies..."
    npm install
    # Ensure www-data can write to node_modules (Vite needs .vite-temp/)
    chown -R www-data:www-data /var/www/node_modules
fi

# Run Laravel setup commands
echo "Running Laravel setup..."
php artisan migrate --force
php artisan optimize:clear
php artisan queue:restart

echo "=== Laravel setup complete ==="

# =============================================================================
# START SUPERVISOR
# =============================================================================

echo "Starting supervisor (php-fpm + npm dev + queue + scheduler)..."
exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
