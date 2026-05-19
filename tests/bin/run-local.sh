#!/usr/bin/env bash
# Run the plugin's PHPUnit suite locally against a Dockerised MariaDB.
#
# Usage: tests/bin/run-local.sh [phpunit-args...]
#   e.g. tests/bin/run-local.sh --filter test_site_option_update_triggers_flushall
#
# Env overrides:
#   WP_VERSION   WordPress version to install (default: 6.0)
#   PHP_VERSION  PHP tag for the php:<ver>-cli image (default: 8.2)
#   DB_SERVICE   docker compose service name of the DB (default: db)
#   DB_NAME      test database name (default: wordpress_test)

set -euo pipefail

WP_VERSION=${WP_VERSION:-6.0}
PHP_VERSION=${PHP_VERSION:-8.2}
DB_SERVICE=${DB_SERVICE:-db}
DB_NAME=${DB_NAME:-wordpress_test}

# Resolve paths relative to the plugin root (this script's parent's parent).
PLUGIN_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)
# docker compose file lives at the repo root; walk up from the plugin dir.
COMPOSE_DIR=$(cd -- "$PLUGIN_DIR/../.." && pwd)
CACHE_DIR=${CACHE_DIR:-/tmp/smoxy-wp-tests}

echo "==> Plugin:   $PLUGIN_DIR"
echo "==> Compose:  $COMPOSE_DIR"
echo "==> WP:       $WP_VERSION   PHP: $PHP_VERSION"

# 1) Ensure the DB service is up.
if ! docker compose -f "$COMPOSE_DIR/docker-compose.yml" ps --services --status running | grep -qx "$DB_SERVICE"; then
    echo "==> Starting '$DB_SERVICE' service…"
    docker compose -f "$COMPOSE_DIR/docker-compose.yml" up -d "$DB_SERVICE"
fi

# 2) Wait for mariadb to accept connections.
DB_CONTAINER=$(docker compose -f "$COMPOSE_DIR/docker-compose.yml" ps -q "$DB_SERVICE")
echo -n "==> Waiting for DB"
for _ in $(seq 1 30); do
    if docker exec "$DB_CONTAINER" mariadb-admin ping -uroot -prootpass --silent >/dev/null 2>&1; then
        echo " — ready."
        break
    fi
    echo -n "."
    sleep 1
done

# 3) Ensure the test database exists.
docker exec "$DB_CONTAINER" mariadb -uroot -prootpass \
    -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`;" >/dev/null

# 4) Resolve the compose network so the PHP container can reach '$DB_SERVICE'.
NETWORK=$(docker inspect "$DB_CONTAINER" --format '{{range $k,$v := .NetworkSettings.Networks}}{{$k}}{{end}}' | head -1)

# 5) Cache dir for the WP test lib so we don't redownload every run.
mkdir -p "$CACHE_DIR"

# 6) Run install-wp-tests.sh (if cache is cold) and phpunit inside a throwaway
#    php:<ver>-cli container joined to the compose network.
docker run --rm \
    --network "$NETWORK" \
    -v "$PLUGIN_DIR:/app" \
    -v "$CACHE_DIR:/tmp/wp-tests" \
    -w /app \
    -e WP_TESTS_DIR=/tmp/wp-tests/wordpress-tests-lib \
    -e WP_CORE_DIR=/tmp/wp-tests/wordpress \
    "php:${PHP_VERSION}-cli" \
    bash -c "
        set -e
        if ! command -v svn >/dev/null; then
            apt-get update -qq >/dev/null
            apt-get install -yq subversion >/dev/null
        fi
        if ! php -m | grep -q '^mysqli\$'; then
            docker-php-ext-install mysqli >/dev/null 2>&1
        fi
        if [ ! -f /tmp/wp-tests/wordpress-tests-lib/includes/functions.php ]; then
            echo '==> Installing WordPress test library (cold cache)…'
            bash tests/bin/install-wp-tests.sh '$DB_NAME' root rootpass '$DB_SERVICE:3306' '$WP_VERSION' true >/dev/null
        fi
        echo '==> Running phpunit'
        vendor/bin/phpunit $*
    "
