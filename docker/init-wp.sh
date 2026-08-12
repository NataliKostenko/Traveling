#!/bin/bash
set -uo pipefail

echo "=== Environment check ==="
echo "WORDPRESS_DB_USER=${WORDPRESS_DB_USER:-<not set>}"
echo "WORDPRESS_DB_NAME=${WORDPRESS_DB_NAME:-<not set>}"
echo "SITE_DOMAIN=${SITE_DOMAIN:-<not set>}"

if [ ! -f /var/www/html/wp-config.php ]; then
    echo "Creating wp-config.php..."
    wp config create --allow-root --path=/var/www/html \
        --dbname="$WORDPRESS_DB_NAME" \
        --dbuser="$WORDPRESS_DB_USER" \
        --dbpass="$WORDPRESS_DB_PASSWORD" \
        --dbhost="${WORDPRESS_DB_HOST:-db:3306}" \
        --skip-check
    echo "wp-config.php created."
else
    echo "wp-config.php already exists."
fi

echo "Detecting mysql client type..."
CLIENT_VERSION=$(mysql --version 2>&1)
echo "$CLIENT_VERSION"
if echo "$CLIENT_VERSION" | grep -qi "mariadb"; then
    echo "Client: MariaDB -> using --skip-ssl"
    MYSQL_OPTS="--skip-ssl"
else
    echo "Client: MySQL -> using --ssl-mode=DISABLED"
    MYSQL_OPTS="--ssl-mode=DISABLED"
fi

echo "Waiting for the database to start..."
attempt=0
max_attempts=60
until mysql -h"db" -u"$WORDPRESS_DB_USER" -p"$WORDPRESS_DB_PASSWORD" $MYSQL_OPTS -e "SELECT 1;" >/dev/null 2>&1; do
    attempt=$((attempt+1))
    echo "Attempt $attempt/$max_attempts: database not responding yet..."
    if [ "$attempt" -ge "$max_attempts" ]; then
        echo "ERROR: database did not respond within $max_attempts seconds."
        echo "Diagnostics of the last attempt:"
        mysql -h"db" -u"$WORDPRESS_DB_USER" -p"$WORDPRESS_DB_PASSWORD" $MYSQL_OPTS -e "SELECT 1;"
        exit 1
    fi
    sleep 1
done
echo "Database is responding."

echo "Checking for wp_options table..."
TABLE_CHECK=$(mysql -h"db" -u"$WORDPRESS_DB_USER" -p"$WORDPRESS_DB_PASSWORD" $MYSQL_OPTS -e "USE $WORDPRESS_DB_NAME; SHOW TABLES;" 2>&1)
echo "SHOW TABLES result:"
echo "$TABLE_CHECK"

if ! echo "$TABLE_CHECK" | grep -q "wp_options"; then
    echo "Importing database template..."
    if [ ! -f /templates/clean_local.sql ]; then
        echo "ERROR: file /templates/clean_local.sql not found inside the container!"
        exit 1
    fi

    mysql -h"db" -u"$WORDPRESS_DB_USER" -p"$WORDPRESS_DB_PASSWORD" $MYSQL_OPTS "$WORDPRESS_DB_NAME" < /templates/clean_local.sql
    echo "Import finished with exit code: $?"

    if ! command -v wp >/dev/null 2>&1; then
        echo "WARNING: WP-CLI (wp) not found, search-replace skipped."
    else
        echo "Replacing domain placeholder..."
        wp search-replace "__SITE_URL__" "http://${SITE_DOMAIN}" --allow-root --path=/var/www/html

        echo "Clearing cached CSS files containing the placeholder (Spectra/Astra/etc.)..."
        find /var/www/html/wp-content/uploads -iname "*.css" -exec grep -li "site_url" {} \; 2>/dev/null | while read -r f; do
            echo "  Removing stale cache file: $f"
            rm -f "$f"
        done

        echo "Flushing permalinks..."
        wp rewrite flush --allow-root --path=/var/www/html
    fi
else
    echo "wp_options table already exists, import not required."
fi

echo "Starting Apache..."
exec "$@"