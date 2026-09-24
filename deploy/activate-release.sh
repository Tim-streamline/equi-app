#!/usr/bin/env bash
set -euo pipefail
umask 022

site="${1:?site required}"
release="${2:?release required}"
mode="${3:?mode required}"
library="${4:-0}"
seed="${5:-0}"
[[ "$site" == /home/ploi/equi-app.staging.optimize-it.nl ]] || { echo 'Unexpected staging directory' >&2; exit 1; }
[[ "$release" =~ ^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{7,12}-[a-f0-9]{6}$ ]] || { echo 'Invalid release ID' >&2; exit 1; }
[[ "$mode" == deploy || "$mode" == rollback ]] || exit 1
[[ "$library" =~ ^[01]$ && "$seed" =~ ^[01]$ ]] || exit 1
cd "$site"
exec 9>shared/deploy.lock
flock -n 9 || { echo 'Another deployment is running' >&2; exit 1; }
php=php8.5
destination="$site/releases/$release"
previous="$(readlink -f current || true)"
switched=0
maintenance=0
nginx_changed=0
nginx_tool="$site/shared/deploy-tools/ploi-nginx.php"
nginx_backup=
finish() {
    result=$?
    trap - EXIT
    if ((result != 0 && switched)) && [[ -n "$previous" && -d "$previous" ]]; then
        ln -s "$previous" "$site/.current-rollback-$$"
        mv -Tf "$site/.current-rollback-$$" "$site/current"
        sudo -n /usr/sbin/service php8.5-fpm reload || true
        echo 'Activation failed; restored the previous code release. Database migrations are not reversed.' >&2
    fi
    if ((result != 0 && nginx_changed)) && [[ -s "$nginx_backup" ]]; then
        if ! "$php" "$nginx_tool" apply "$nginx_backup"; then
            echo "WARNING: Nginx restoration failed. Saved configuration: $nginx_backup" >&2
        fi
    fi
    if ((maintenance)) && [[ -f "$site/current/artisan" ]]; then
        (cd "$site/current" && "$php" artisan up) || true
    fi
    exit "$result"
}
trap finish EXIT

if [[ "$mode" == deploy ]]; then
    [[ ! -e "$destination" ]] || { echo 'Release already exists' >&2; exit 1; }
    (cd incoming && sha256sum -c "$release.tar.gz.sha256")
    mkdir "$destination"
    tar -xzf "incoming/$release.tar.gz" -C "$destination" --no-same-owner
    [[ "$(cat "$destination/RELEASE_ID")" == "$release" && ! -e "$destination/.env" && ! -e "$destination/storage" ]] || exit 1
    ln -s "$site/shared/.env" "$destination/.env"
    ln -s "$site/shared/storage" "$destination/storage"
    ln -s "$site/shared/storage/app/public" "$destination/public/storage"
else
    [[ -f "$destination/.deploy/check-release.php" ]] || { echo 'Unknown rollback release' >&2; exit 1; }
fi
cd "$destination"
"$php" artisan config:clear
"$php" artisan package:discover --ansi
"$php" .deploy/check-release.php
# On rollback regenerate config caches from the current shared environment too.
"$php" artisan config:cache
"$php" artisan route:cache
"$php" artisan view:cache
# Capture live configuration before any database or release changes.
[[ -s "$nginx_tool" ]] || { echo 'Nginx deployment helper is missing' >&2; exit 1; }
mkdir -p "$site/shared/nginx-releases"
nginx_backup_dir="$(mktemp -d "$site/shared/backups/nginx-$release-XXXXXX")"
nginx_backup="$nginx_backup_dir/site.conf"
"$php" "$nginx_tool" snapshot "$nginx_backup"
if [[ -n "$previous" && -f "$previous/RELEASE_ID" ]]; then
    previous_id="$(cat "$previous/RELEASE_ID")"
    [[ "$previous_id" =~ ^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{7,12}-[a-f0-9]{6}$ ]] || exit 1
    cp "$nginx_backup" "$site/shared/nginx-releases/$previous_id.conf"
fi
nginx_config="$destination/.deploy/nginx-staging.conf"
if [[ ! -s "$nginx_config" && "$mode" == rollback ]]; then
    nginx_config="$site/shared/nginx-releases/$release.conf"
fi
[[ -s "$nginx_config" ]] || { echo 'No saved Nginx configuration for this release; refusing to switch' >&2; exit 1; }
if [[ "$mode" == deploy ]]; then
    "$php" .deploy/backup-database.php "$site/shared/backups/$release.dump"
    if [[ -f "$site/current/artisan" ]]; then
        (cd "$site/current" && "$php" artisan down --retry=15)
        maintenance=1
    fi
    "$php" artisan migrate --force --no-interaction
    if [[ ! -f storage/app/private/powersync_private.pem && ! -f storage/app/private/powersync_public.pem ]]; then
        "$php" artisan powersync:generate-keys
        chmod 600 storage/app/private/powersync_private.pem
    fi
    if ((seed)); then
        for seeder in IntakeQuestionnaireSeeder VoedingAdviesSeeder ManagementAdviesSeeder BewegingAdviesSeeder ProtocolTemplateSeeder; do
            "$php" artisan db:seed --class="$seeder" --force --no-interaction
        done
    fi
    if ((library)); then
        "$php" artisan deploy-library-items --path="$site/shared/lib-assets"
    fi
fi

# Keep Ploi's initial placeholder directory, including ACME files, on first deploy.
if [[ -d "$site/current" && ! -L "$site/current" ]]; then
    [[ ! -f "$site/current/artisan" ]] || { echo 'Existing app must be migrated to the release layout first' >&2; exit 1; }
    previous="$site/releases/ploi-bootstrap"
    [[ ! -e "$previous" ]] || exit 1
    mv "$site/current" "$previous"
fi
ln -s "$destination" "$site/.current-$release"
mv -Tf "$site/.current-$release" "$site/current"
switched=1
sudo -n /usr/sbin/service php8.5-fpm reload
"$php" artisan queue:restart
"$php" artisan up
maintenance=0
nginx_changed=1
"$php" "$nginx_tool" apply "$nginx_config"
curl --fail --silent --show-error --retry 5 --retry-all-errors --retry-delay 2 --max-time 20 \
    --resolve equi-app.staging.optimize-it.nl:443:127.0.0.1 https://equi-app.staging.optimize-it.nl/up >/dev/null
curl --fail --silent --show-error --max-time 20 \
    --resolve equi-app.staging.optimize-it.nl:443:127.0.0.1 https://equi-app.staging.optimize-it.nl/admin/login >/dev/null
if [[ -s web-dist/index.html ]]; then
    for path in / /onboarding/welcome /protocol; do
        curl --fail --silent --show-error --max-time 20 \
            --resolve equi-app.staging.optimize-it.nl:443:127.0.0.1 "https://equi-app.staging.optimize-it.nl$path" | cmp - web-dist/index.html
    done
    # Browser sessions must reach Laravel, not the SPA fallback.
    curl --fail --silent --show-error --max-time 20 \
        --resolve equi-app.staging.optimize-it.nl:443:127.0.0.1 https://equi-app.staging.optimize-it.nl/web-session/csrf >/dev/null
fi
for path in / /admin/login '/protocol?tab=kalender'; do
    redirect="$(curl --silent --show-error --max-time 20 --output /dev/null --write-out '%{http_code} %{redirect_url}' \
        --resolve equi-app.staging.optimize-it.nl:80:127.0.0.1 "http://equi-app.staging.optimize-it.nl$path")"
    [[ "$redirect" =~ ^30[18]\ https://equi-app.staging.optimize-it.nl ]] && \
        [[ "${redirect#* }" == "https://equi-app.staging.optimize-it.nl$path" ]] || { echo "HTTP redirect check failed for $path" >&2; exit 1; }
done
printf '%s\n' "$release" > "$site/shared/last-successful-release"
echo "Activated $release: https://equi-app.staging.optimize-it.nl"
echo 'Previous releases and database backups are retained under the site directory.'
