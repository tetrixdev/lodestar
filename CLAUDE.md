# slim-docker-laravel-setup

Docker-based Laravel development and production environment.

## Version

Check `.slim-docker-version` file in the project root. This file contains the git commit hash of slim-docker-laravel-setup that was used to set up this project.

If the file is missing, the project was set up before version tracking was added and should be updated.

To check if updates are available:
```bash
# Current version in project
cat .slim-docker-version

# Latest version on main branch
curl -s https://api.github.com/repos/tetrixdev/slim-docker-laravel-setup/commits/main | jq -r '.sha'
```

## Structure

```text
docker-laravel/
├── local/           # Local development configs
│   ├── php/         # PHP Dockerfile, entrypoint, supervisor configs
│   └── (nginx uses shared config)
├── production/      # Production configs
│   ├── php/         # Production Dockerfile, entrypoint, supervisor configs
│   └── nginx/       # Production nginx Dockerfile
└── shared/          # Shared between local/production
    ├── nginx/       # Nginx config (default.conf)
    ├── php/         # PHP config (local.ini)
    └── supervisor/  # Main supervisord.conf

deploy/              # Production deployment
└── compose.yml      # Production compose file
```

## Containers

| Container | Local | Production | Purpose |
|-----------|-------|------------|---------|
| php | Build from Dockerfile | ghcr.io image | PHP-FPM + Vite (local) + Queue + Scheduler |
| nginx | nginx:1.27-alpine | ghcr.io image | Web server |
| postgres | postgres:17-alpine | postgres:17-alpine | Database |
| redis | redis:7-alpine | redis:7-alpine | Cache, sessions, queues |

## Key Differences: Local vs Production

| Aspect | Local | Production |
|--------|-------|------------|
| **Queue** | `queue:listen` (picks up code changes) | `queue:work` (efficient) |
| **Vite** | `npm run dev` via supervisor | Assets pre-built |
| **Logging** | stdout/stderr | Files in storage/logs |
| **Workers** | 1 queue worker | 2 queue workers |

## Port Configuration

All ports configurable via `.env`:
- `NGINX_PORT` - Web server (default: 80)
- `VITE_PORT` - Vite HMR (default: 5173)
- `DB_EXTERNAL_PORT` - PostgreSQL external (default: 5433, internal always 5432)

Change these when running multiple projects simultaneously.

## Vite HMR Configuration

The `vite.config.js` reads `APP_URL` to configure HMR for external/mobile access:
- `APP_URL=http://localhost` - HMR works only on localhost
- `APP_URL=http://192.168.1.100` - HMR works from any device on the network

This enables testing on mobile devices during development.

## Supervisor Processes

Local dev runs via supervisor (PID 1):
- `php-fpm` - PHP FastCGI Process Manager
- `npm-dev` - Vite dev server with HMR
- `queue-worker` - Laravel queue:listen
- `scheduler` - Laravel schedule:work

Production runs via supervisor:
- `php-fpm` - PHP FastCGI Process Manager
- `queue-worker` - Laravel queue:work (x2)
- `scheduler` - Laravel schedule:work

## Updating from slim-docker-laravel-setup

1. Check current version: `cat .slim-docker-version` (if missing, very outdated)
2. Check latest version: https://github.com/tetrixdev/slim-docker-laravel-setup/releases
3. Run the installer to update:
   ```bash
   curl -sSL https://raw.githubusercontent.com/tetrixdev/slim-docker-laravel-setup/main/install.sh | bash
   ```
   The installer refreshes the infrastructure files and then runs `setup.sh`
   automatically with your detected project values. Your existing `.env` is
   always preserved (`APP_KEY` and `DB_PASSWORD` are never regenerated).
4. Review the changes with `git diff` and re-apply any manual customizations to
   docker configs if needed.
5. The `.slim-docker-version` file is updated automatically.

## Customizable vs Template Files

**Template files** (update from upstream):
- `docker-laravel/**/*` - Docker configs
- `.github/workflows/docker-laravel.yml` - Build workflow

**Customizable files** (merge carefully):
- `compose.yml` - May have project-specific services
- `.env.example` - May have project-specific vars
- `vite.config.js` - May have additional plugins

## Common Tasks

### Add a PHP extension
Edit both Dockerfiles:
- `docker-laravel/local/php/Dockerfile`
- `docker-laravel/production/php/Dockerfile`

### Change queue worker count
Edit `docker-laravel/production/php/supervisor/queue-work.conf`:
```ini
numprocs=4  # Change from 2 to 4
```

### Disable scheduler
Remove or rename `schedule-work.conf` in supervisor directory.

### Database backup
```bash
docker compose exec php pg_dump -h postgres -U {{PROJECT_NAME}} {{PROJECT_NAME}} > backup.sql
```

## Naming convention: service-names for internal hostnames

Compose **service keys** (`php`, `nginx`, `postgres`, `redis`) are intentionally
short and stable. Docker auto-aliases each container by its service name on
every network it joins, so:

- The shared nginx config can `fastcgi_pass php:9000;` without knowing the
  project's name.
- `.env` uses `DB_HOST=postgres`, `REDIS_HOST=redis` — same pattern for dev
  and production, independent of `COMPOSE_PROJECT_NAME`.

**Container_names** keep the `<PROJECT_NAME>-*` prefix so externally-visible
identifiers (in `docker ps`, on a shared `main-network`, in proxy-nginx
upstreams, in ghcr image paths) remain namespaced per project.

**Important constraint:** app-internal services (php, postgres, redis) must
**not** join an external shared network. The short service-name alias would
otherwise be advertised there and could collide with a sibling project that
uses the same convention. Only externally-proxied services (the web `nginx`,
or a WebSocket bridge) should join the shared proxy network.

Externally-proxied services still publish their short alias on the shared
network — two sibling projects with `nginx` on `main-network` will both
advertise `nginx`, so anything routing across that network (e.g. proxy-nginx)
must target the **container_name** (`<PROJECT_NAME>-nginx`), not the bare
service alias.
