# Staging deployment

Run from the repository root:

```bash
./deploy-staging
```

This builds the current working tree's backend inside the local Sail PHP 8.5 Docker image, including locked production Composer dependencies and compiled Svelte/Vite assets. It uploads a checksummed release to **`ploi@37.97.209.204` (`opt-staging`)** and activates it at **https://equi-app.staging.optimize-it.nl**. Uncommitted application changes are included; the Git revision and working-tree status are recorded in the artifact.

Local requirements: Docker, Bash, Git, rsync, tar, sha256sum and working SSH-key access as `ploi`. No host PHP, Composer or Node is needed. The existing `sail-8.5/app` image is used; if missing, the script builds it from `backend/compose.yaml` (which requires the local Sail dependency). Builds use a disposable directory, so local `vendor`, `node_modules`, `.env`, storage and the running Sail app are untouched.

```bash
./deploy-staging --build-only            # Save a release without connecting to staging
./deploy-staging --seed-catalog          # Also seed intake, advice catalogs and protocol templates
./deploy-staging --with-library          # Also transfer and import deploy/lib-assets
./deploy-staging --with-library --seed-catalog
./deploy-staging --artifact deploy/builds/RELEASE_ID.tar.gz  # Deploy an existing verified build
./deploy-staging --rollback RELEASE_ID   # Activate a retained code release
```

`RELEASE_ID` is the timestamp, Git revision and random suffix printed by the build. Build archives and the library bundle are excluded from Git. The first media transfer is 7.49 GiB; rsync retains interrupted files for retry and subsequent transfers send only changed files. Library import is optional and must finish checksum validation before records are changed. Default code deployments do not transfer media or overwrite catalog edits.

The default deployment acquires a server-side lock, validates the staging environment and database identity, creates a PostgreSQL backup, briefly enables maintenance mode for migrations, refreshes Laravel caches, switches `current`, reloads PHP-FPM, and checks HTTPS `/up` and `/admin/login`. A failed activation restores the previous code release. Rollback does **not** reverse migrations or restore database contents; migrations must remain compatible with the previous release. Database backups and old releases are retained for explicit cleanup.

## Ploi configuration

Configured through the Ploi MCP:

- Server `opt-staging`: **121767**, PHP 8.5 and PostgreSQL 18.
- Site `equi-app.staging.optimize-it.nl`: **406977**, user `ploi`, project root `/current`, web directory `/current/public`.
- Dedicated database and user `equi_app_staging` (database **420490**).
- Let's Encrypt certificate, Nginx upload limit 100 MB, PHP upload limit 100 MB, PHP execution limited to the front controller, and search-engine exclusion.
- Laravel scheduler every minute and a non-overlapping, bounded database queue worker every minute. A queued job can wait up to a minute before a worker starts. Ploi stores these in `/etc/crontab` and captures output in `~/.ploi/scheduled-333490.log` and `~/.ploi/scheduled-333491.log`.

Ploi manages the site environment; `.env` at the site root is a symlink into `shared`. Secrets were generated specifically for staging. Mail uses the log driver. The normal demo `DatabaseSeeder` and its default-password accounts are never run. Create an admin account with a unique password explicitly before signing in; no local customer or admin accounts are copied.

There is no Git repository attached in Ploi and quick deploy is disabled. Run `./deploy-staging` locally; the Ploi Deploy button is deliberately disabled with an explanatory script because it cannot build the local artifact.

Server layout under `/home/ploi/equi-app.staging.optimize-it.nl`:

```text
.env -> shared/.env
current -> releases/RELEASE_ID
releases/RELEASE_ID/          # Backend, vendor, built assets, .env/storage symlinks
incoming/                   # Uploaded archives and SHA-256 checksums
shared/.env                 # Persistent staging configuration, mode 0600
shared/storage/             # Uploads, sessions, compiled views, logs and signing keys
shared/backups/             # PostgreSQL backups, mode 0600
shared/lib-assets/           # Optional separately transferred library bundle
```

Storage and signing keys survive deployments and code rollback. Back up `shared/storage` and `shared/.env` separately; PostgreSQL backups do not contain these files. The scripts do not provision or deploy the separate PowerSync service. Its staging URL is reserved in the environment, but mobile synchronization requires that service to be configured separately.

FFmpeg 8.0.1 is installed under `shared/tools` for new-video thumbnail generation, using official Ubuntu packages extracted into a project-local runtime. `FFMPEG_BINARY` points to its wrapper. System packages are not changed. To refresh this runtime on opt-staging, run `ssh ploi@37.97.209.204 bash -s < deploy/install-media-tools.sh`, then refresh the Ploi environment/config cache if its path changed. The installer verifies a real JPEG encode before activating the runtime and retains package/version records. It is separate from routine code deployments.

`staging.env.example` documents the environment without secrets; `nginx-staging.conf` records the Ploi site configuration. Ploi owns the included TLS configuration and certificate renewal.

Deployment regression checks:

```bash
python3 deploy/test-deployment.py
bash -n deploy-staging deploy/*.sh
```

## Library deployment

`deploy-library-items` exports and imports the real library independently of the demo `LibrarySeeder`.

## Export from the source environment

From the repository root:

```bash
./deploy-library-items --export
./deploy-library-items --verify
```

The bundle is stored in **`deploy/lib-assets/` at the repository root**. The entire directory is excluded from Git. Keep it with your deployment backups and transfer it separately from the code.

The bundle contains:

- `manifest.json`: all library items, including drafts, publication and access settings, chapters, article sections, categories and their links, referenced author profiles, and uploaded-media metadata.
- `files/`: original images, videos, audio, thumbnails, and referenced legacy public uploads, named by their SHA-256 checksum. The manifest maps these files back to their storage paths.

Unassigned library uploads are included because existing articles can embed them from the shared media pool. Customer accounts, bookmarks, progress, and uploader accounts are not exported. External URLs stay external; the command does not download third-party websites or hosted embeds.

Export streams large files instead of loading them into memory. The manifest is replaced only after the complete export succeeds. Files from earlier exports may remain in `files/`; only files referenced by the current manifest are deployed.

## Deploy to another environment

1. Deploy the application code, install backend dependencies, and run its database migrations.
2. Copy the complete `deploy/lib-assets/` directory to the same location in the destination checkout. For example:

   ```bash
   rsync -av --partial deploy/lib-assets/ user@server:/path/to/equi-app/deploy/lib-assets/
   ```

3. Configure the destination backend's `APP_URL` / `PUBLIC_STORAGE_URL` and its public storage disk. As with normal Laravel uploads, `public/storage` must expose that disk (`php artisan storage:link`). Reload cached configuration if you changed those settings.
4. From the destination repository root, run:

   ```bash
   ./deploy-library-items --verify
   ./deploy-library-items
   ```

The default action imports into the backend database configured for that environment. It checks every file's checksum before making changes, restores files to their original storage paths, and rewrites embedded media URLs and thumbnail URLs using the destination disk URL.

Repeated imports update matching items and categories without duplicating them. Items/categories are matched by source UUID or slug; existing destination IDs are retained so bookmarks, progress, and other references remain valid. An ambiguous UUID/slug match is rejected. Existing author profiles are reused; absent authors are created from the bundle.

Chapters, article sections, and category membership are synchronized for imported items. Destination-only items, media, categories, and customer data remain. Conflicting destination files are rejected rather than overwritten. Database changes run in one transaction; newly installed files are removed if the import fails.

## Runtime and custom paths

The wrapper uses host PHP when available, otherwise a temporary Laravel container on the existing Compose network. The Docker path mounts only this bundle in addition to the service's usual mounts and does not restart the backend services. Start the normal backend containers before using it.

```bash
DEPLOY_LIBRARY_RUNTIME=docker ./deploy-library-items --export
DEPLOY_LIBRARY_RUNTIME=php ./deploy-library-items
LIBRARY_BUNDLE_PATH=/deploy/lib-assets ./deploy-library-items --verify
```

You can also invoke Artisan directly, which is useful when the deployed project consists only of `backend/`:

```bash
php artisan deploy-library-items --path=/deploy/lib-assets
php artisan deploy-library-items --export --path=/deploy/lib-assets
php artisan deploy-library-items --verify --path=/deploy/lib-assets
```
