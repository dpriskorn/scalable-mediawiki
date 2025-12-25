#!/usr/bin/env bash
set -euo pipefail

# -------- CONFIG --------
COMPOSE_FILE=${COMPOSE_FILE:-docker-compose.yml}
MEDIAWIKI_SERVICE=${MEDIAWIKI_SERVICE:-mediawiki}
PHP_USER=${PHP_USER:-www-data}
MW_PATH=${MW_PATH:-/var/www/html/w}
# ------------------------

echo "==> Running MediaWiki PHPUnit tests in Docker"
echo "Compose file: $COMPOSE_FILE"
echo "Service:      $MEDIAWIKI_SERVICE"
echo "MW path:      $MW_PATH"
echo

if ! docker compose -f "$COMPOSE_FILE" ps --services | grep -qx "$MEDIAWIKI_SERVICE"; then
  echo "ERROR: Service '$MEDIAWIKI_SERVICE' not found in $COMPOSE_FILE"
  echo "Available services:"
  docker compose -f "$COMPOSE_FILE" ps --services
  exit 1
fi

echo
echo "==> Running composer phpunit"

docker compose -f "$COMPOSE_FILE" exec \
  -u "$PHP_USER" \
  "$MEDIAWIKI_SERVICE" \
  bash -lc "
    cd '$MW_PATH' &&
    composer phpunit -- $*
  "

echo
echo "==> PHPUnit tests completed ✅"
