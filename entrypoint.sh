#!/bin/bash
set -euo pipefail

# Only the main container may install MediaWiki
if [ "${MW_ROLE:-runner}" != "main" ]; then
  exec "$@"
fi

cd /var/www/html/w

MYSQL="mysql -h vitess -P 15309 -u root --protocol=tcp"

echo "Waiting for Vitess to accept connections..."
until $MYSQL -e "SELECT 1" >/dev/null 2>&1; do
  sleep 2
done
echo "Vitess is ready"

# Prevent double-install in restart loops
INSTALL_LOCK=".installing"

if [ -f .installed ]; then
  echo "MediaWiki already installed, skipping installer"
  exec "$@"
fi

if [ -f "$INSTALL_LOCK" ]; then
  echo "Installation already in progress, waiting..."
  while [ ! -f .installed ]; do
    sleep 2
  done
  exec "$@"
fi

touch "$INSTALL_LOCK"

echo "Checking MediaWiki schema..."
if ! $MYSQL -N -s -e "USE page; SHOW TABLES LIKE 'site_stats';" | grep -q '^site_stats$'; then
  echo "No MediaWiki tables found, running installer..."

  MW_INSTALLING=1 php maintenance/run.php install.php \
    --dbname page \
    --dbtype mysql \
    --dbserver vitess:15309 \
    --scriptpath /w \
    --server http://localhost:8080 \
    --pass dockerpass \
    TestWiki Admin

  touch .installed
  rm -f "$INSTALL_LOCK"

  echo "MediaWiki installation completed"
else
  echo "MediaWiki schema detected, skipping installer"
  rm -f "$INSTALL_LOCK"
fi

exec "$@"
