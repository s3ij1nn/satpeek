#!/bin/sh
set -e

cd /var/www

# Only the main `app` service should run the bootstrap chores (composer install,
# migrate, seed). The `queue` and `scheduler` services share the same image and
# volume, so we don't need them to fight over the same one-shot operations.
ROLE="${SATPEEK_ROLE:-app}"

if [ ! -f .env ]; then
    if [ -f .env.example ]; then
        cp .env.example .env
        echo "[entrypoint] .env created from .env.example"
    fi
fi

if [ "$ROLE" = "app" ]; then
    if [ ! -f vendor/autoload.php ]; then
        echo "[entrypoint] composer install (first boot)"
        # macOS Docker volume mounts race during parallel unzip; serialise to fix.
        rm -rf vendor 2>/dev/null || true
        COMPOSER_MAX_PARALLEL_HTTP=1 composer install \
            --no-interaction --prefer-dist --no-progress --no-scripts \
        || {
            echo "[entrypoint] composer install failed; attempting clean retry"
            rm -rf vendor
            COMPOSER_MAX_PARALLEL_HTTP=1 composer install \
                --no-interaction --prefer-dist --no-progress --no-scripts
        }
        composer dump-autoload --optimize --no-interaction || true
    fi

    if [ -f artisan ]; then
        grep -q "^APP_KEY=base64:" .env 2>/dev/null || php artisan key:generate --force || true
    fi
else
    # queue / scheduler: wait for the app container to finish composer install.
    echo "[entrypoint:${ROLE}] waiting for vendor/autoload.php"
    while [ ! -f vendor/autoload.php ]; do
        sleep 2
    done
    echo "[entrypoint:${ROLE}] vendor ready"
fi

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
chmod -R ug+rwx storage bootstrap/cache 2>/dev/null || true

if [ "$ROLE" = "app" ] && [ -f artisan ]; then
    # docker-compose `depends_on: service_healthy` already gates this container
    # on Postgres readiness, but a brief sanity check covers `docker compose run`
    # invocations that bypass the healthcheck. Read DB_* from .env so we don't
    # need to mirror them in docker-compose `environment:`.
    cat > /tmp/satpeek-db-probe.php <<'PHP'
<?php
$envFile = '/var/www/.env';
$env = file_exists($envFile) ? (parse_ini_file($envFile, false, INI_SCANNER_RAW) ?: []) : [];
$get = function (string $k, string $d = '') use ($env): string {
    if (array_key_exists($k, $env) && $env[$k] !== '') return (string) $env[$k];
    $v = getenv($k);
    return $v !== false && $v !== '' ? (string) $v : $d;
};
try {
    $dsn = sprintf(
        '%s:host=%s;port=%s;dbname=%s',
        $get('DB_CONNECTION', 'pgsql'),
        $get('DB_HOST', 'postgres'),
        $get('DB_PORT', '5432'),
        $get('DB_DATABASE', 'satpeek')
    );
    (new PDO($dsn, $get('DB_USERNAME', 'satpeek'), $get('DB_PASSWORD', 'satpeek')))->query('SELECT 1');
    exit(0);
} catch (Throwable $e) {
    exit(1);
}
PHP
    echo "[entrypoint] waiting for database"
    tries=0
    until php /tmp/satpeek-db-probe.php >/dev/null 2>&1; do
        tries=$((tries + 1))
        if [ "$tries" -ge 60 ]; then
            echo "[entrypoint] DB probe timed out (continuing — depends_on should have ensured readiness)"
            break
        fi
        sleep 2
    done
    rm -f /tmp/satpeek-db-probe.php

    echo "[entrypoint] running migrations"
    php artisan migrate --force --no-interaction || echo "[entrypoint] migrate failed (will retry next start)"

    if [ ! -f storage/.seeded ]; then
        echo "[entrypoint] seeding database (first boot)"
        if php artisan db:seed --force --no-interaction; then
            touch storage/.seeded
        else
            echo "[entrypoint] seed failed (non-fatal)"
        fi
    fi

    # Publish Filament + framework assets to /public on every boot so a
    # composer update of Filament always serves matching asset files. Idempotent.
    echo "[entrypoint] publishing assets"
    php artisan filament:assets --no-interaction 2>/dev/null || true
    php artisan storage:link --no-interaction 2>/dev/null || true

    php artisan config:cache --no-interaction || true
    php artisan route:cache --no-interaction || true
    php artisan view:cache --no-interaction || true
fi

exec "$@"
