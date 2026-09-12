#!/bin/sh
set -e

# Render's free plan has no Shell access, so migrations cannot be run by
# hand after a deploy. Running them here, before the app starts serving
# traffic, means every deploy that adds a migration applies it automatically.
php artisan migrate --force

exec /usr/bin/supervisord -n
