#!/bin/bash
set -uo pipefail

echo "=== Проверка окружения ==="
echo "WORDPRESS_DB_USER=${WORDPRESS_DB_USER:-<не задан>}"
echo "WORDPRESS_DB_NAME=${WORDPRESS_DB_NAME:-<не задан>}"
echo "SITE_DOMAIN=${SITE_DOMAIN:-<не задан>}"

if [ ! -f /var/www/html/wp-config.php ]; then
    echo "Создание wp-config.php..."
    wp config create --allow-root --path=/var/www/html \
        --dbname="$WORDPRESS_DB_NAME" \
        --dbuser="$WORDPRESS_DB_USER" \
        --dbpass="$WORDPRESS_DB_PASSWORD" \
        --dbhost="${WORDPRESS_DB_HOST:-db:3306}" \
        --skip-check
    echo "wp-config.php создан."
else
    echo "wp-config.php уже существует."
fi

echo "Определение типа mysql-клиента..."
CLIENT_VERSION=$(mysql --version 2>&1)
echo "$CLIENT_VERSION"
if echo "$CLIENT_VERSION" | grep -qi "mariadb"; then
    echo "Клиент: MariaDB -> используем --skip-ssl"
    MYSQL_OPTS="--skip-ssl"
else
    echo "Клиент: MySQL -> используем --ssl-mode=DISABLED"
    MYSQL_OPTS="--ssl-mode=DISABLED"
fi

echo "Ожидание запуска базы данных..."
attempt=0
max_attempts=60
until mysql -h"db" -u"$WORDPRESS_DB_USER" -p"$WORDPRESS_DB_PASSWORD" $MYSQL_OPTS -e "SELECT 1;" >/dev/null 2>&1; do
    attempt=$((attempt+1))
    echo "Попытка $attempt/$max_attempts: база ещё не отвечает..."
    if [ "$attempt" -ge "$max_attempts" ]; then
        echo "ОШИБКА: не дождались базы данных за $max_attempts секунд."
        echo "Диагностика последней попытки:"
        mysql -h"db" -u"$WORDPRESS_DB_USER" -p"$WORDPRESS_DB_PASSWORD" $MYSQL_OPTS -e "SELECT 1;"
        exit 1
    fi
    sleep 1
done
echo "База данных отвечает."

echo "Проверка наличия таблицы wp_options..."
TABLE_CHECK=$(mysql -h"db" -u"$WORDPRESS_DB_USER" -p"$WORDPRESS_DB_PASSWORD" $MYSQL_OPTS -e "USE $WORDPRESS_DB_NAME; SHOW TABLES;" 2>&1)
echo "Результат SHOW TABLES:"
echo "$TABLE_CHECK"

if ! echo "$TABLE_CHECK" | grep -q "wp_options"; then
    echo "Импорт шаблона базы данных..."
    if [ ! -f /templates/clean_local.sql ]; then
        echo "ОШИБКА: файл /templates/clean_local.sql не найден внутри контейнера!"
        exit 1
    fi

    mysql -h"db" -u"$WORDPRESS_DB_USER" -p"$WORDPRESS_DB_PASSWORD" $MYSQL_OPTS "$WORDPRESS_DB_NAME" < /templates/clean_local.sql
    echo "Импорт завершён с кодом: $?"

    if ! command -v wp >/dev/null 2>&1; then
        echo "ПРЕДУПРЕЖДЕНИЕ: WP-CLI (wp) не найден, search-replace пропущен."
    else
        echo "Замена плейсхолдера домена..."
        wp search-replace "__SITE_URL__" "http://${SITE_DOMAIN}" --allow-root --path=/var/www/html

        echo "Очистка файлового CSS-кэша с плейсхолдерами (Spectra/Astra/др.)..."
        find /var/www/html/wp-content/uploads -iname "*.css" -exec grep -li "site_url" {} \; 2>/dev/null | while read -r f; do
            echo "  Удаляю устаревший кэш: $f"
            rm -f "$f"
        done

        echo "Обновление permalinks..."
        wp rewrite flush --allow-root --path=/var/www/html
    fi
else
    echo "Таблица wp_options уже существует, импорт не требуется."
fi

echo "Запуск Apache..."
exec "$@"