#!/bin/sh
set -e

cd /var/www/html

KEY_FILE=storage/app/app.key

if [ -z "$APP_KEY" ]; then
    if [ "$1" = "php-fpm" ] && [ ! -s "$KEY_FILE" ]; then
        php artisan key:generate --show > "$KEY_FILE"
    fi

    until [ -s "$KEY_FILE" ]; do
        sleep 1
    done

    APP_KEY="$(cat "$KEY_FILE")"
    export APP_KEY
fi

if [ "$1" = "php-fpm" ]; then
    php artisan migrate --force
    touch /tmp/ready
fi

exec "$@"
