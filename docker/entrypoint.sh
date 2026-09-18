#!/bin/sh
set -e

# Render's free plan has no Shell access, so migrations cannot be run by
# hand after a deploy. Running them here, before the app starts serving
# traffic, means every deploy that adds a migration applies it automatically.
php artisan migrate --force

# Config/route caching must happen here rather than at image build time:
# Render only injects real env vars (DB_HOST, APP_KEY, etc.) into the running
# container, not into `docker build`, so caching config during the build
# would bake in empty/wrong values. Without any of this, Laravel re-parses
# every config file and re-registers every route on every single request.
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec /usr/bin/supervisord -n
