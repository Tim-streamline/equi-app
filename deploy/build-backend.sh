#!/usr/bin/env bash
set -euo pipefail

[[ "$PWD" == /build && -f RELEASE_ID && ! -e .env ]] || { echo 'Run this through ./deploy-staging' >&2; exit 1; }
# Build dependencies are installed in this disposable checkout only.
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader --no-scripts
APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr php artisan package:discover --ansi
npm ci --no-audit --no-fund
# The local npm build hook reloads Octane; a release build has no running server.
VITE_APP_NAME='EquiNova Staging' ./node_modules/.bin/vite build
test -s public/build/manifest.json
test ! -e public/hot
composer check-platform-reqs --no-dev
find app bootstrap config routes database -type f -name '*.php' -print0 | xargs -0 -n1 php -l > /tmp/equi-php-lint.log
rm -rf node_modules storage
# Discovery/config caches contain build-machine paths and must be made on target.
find bootstrap/cache -maxdepth 1 -type f -name '*.php' -delete
printf 'User-agent: *\nDisallow: /\n' > public/robots.txt
