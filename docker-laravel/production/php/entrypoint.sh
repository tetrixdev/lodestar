#!/bin/bash
set -e

echo "=== slim-docker-laravel-setup v0.1.0 ==="
echo "Starting production environment..."

# =============================================================================
# LARAVEL SETUP
# =============================================================================

cd /var/www

# Generate Laravel application key if not set
if [ -f ".env" ] && ! grep -q "^APP_KEY=.\+" .env; then
    echo "Generating Laravel application key..."
    php artisan key:generate --no-interaction --force
fi

# Run Laravel production optimizations
echo "Running Laravel optimizations..."
php artisan migrate --force --isolated --no-interaction
# Lodestar customization: upsert the base (system-scope) skills from code on every
# boot. Idempotent — only republishes a skill whose body actually changed — so a
# deploy that updated a base skill ships the new version automatically.
php artisan db:seed --class=SystemPlaybookSeeder --force --no-interaction
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction
php artisan queue:restart --no-interaction

echo "=== Laravel setup complete ==="

# =============================================================================
# START SUPERVISOR
# =============================================================================

echo "Starting supervisor (php-fpm + queue worker + scheduler)..."
exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
