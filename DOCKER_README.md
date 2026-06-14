# Laravel Docker Environment

This project uses Docker for local development and production deployment.

## Quick Start

```bash
# Start containers
docker compose up -d

# Check status
docker compose ps
```

## Daily Commands

Service names in `compose.yml` (`php`, `nginx`, `postgres`, `redis`) are short
and stable. Use them directly with `docker compose exec` / `logs`; you don't
need to prefix the project name.

### Laravel Commands
```bash
# Run migrations
docker compose exec php php artisan migrate

# Fresh migration with seeding
docker compose exec php php artisan migrate:fresh --seed

# Generate application key
docker compose exec php php artisan key:generate

# Clear all caches
docker compose exec php php artisan optimize:clear

# Run tinker
docker compose exec php php artisan tinker
```

### Package Management
```bash
# Install/update Composer dependencies
docker compose exec php composer install
docker compose exec php composer update

# Install/update NPM dependencies
docker compose exec php npm install
docker compose exec php npm update

# Build frontend assets
docker compose exec php npm run build
```

### Container Management
```bash
# View logs
docker compose logs -f php
docker compose logs -f nginx
docker compose logs -f postgres

# Restart containers
docker compose restart

# Stop containers
docker compose down

# Access PHP container shell
docker compose exec php bash
```

### Database Access
```bash
# Access PostgreSQL
docker compose exec postgres psql -U lodestar -d lodestar
```

## Environments

- **Local Development**: Uses `compose.yml` with hot-reload and debugging
- **Production**: See `docker-laravel/production/README.md` for deployment instructions

## Ports

Ports are configurable in `.env`:

| Service     | Default Port | .env Variable       |
| ----------- | ------------ | ------------------- |
| Web (Nginx) | 80           | `NGINX_PORT`        |
| Vite HMR    | 5173         | `VITE_PORT`         |
| PostgreSQL  | 5433         | `DB_EXTERNAL_PORT`  |

Example for running multiple projects:
```bash
# .env
NGINX_PORT=8081
VITE_PORT=5174
DB_EXTERNAL_PORT=5434
```

## External/Mobile Access

To access your app from mobile devices or other machines on the network:

1. Set `APP_URL` in `.env` to your machine's IP:
   ```bash
   APP_URL=http://192.168.1.100
   ```

2. Restart containers:
   ```bash
   docker compose restart
   ```

The Vite config automatically uses `APP_URL` for HMR, so hot-reload works on external devices.

## Troubleshooting

### Permission Issues
```bash
docker compose exec php chown -R www-data:www-data storage bootstrap/cache
```

### Container Won't Start
```bash
# Check logs for specific service
docker compose logs php
```

### Database Connection Failed
- Ensure `.env` has correct DB credentials
- Check if PostgreSQL container is healthy: `docker compose ps`

### Vite HMR Not Working
- Ensure VITE_PORT is not blocked by firewall
- For external access, verify `APP_URL` is set to your machine's IP (not localhost)
- Check Vite logs: `docker compose logs php | grep vite`
