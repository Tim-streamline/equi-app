# Staging PowerSync configuration

These templates target `equi-app.online`. They use the same pinned PowerSync and MongoDB images verified locally. PowerSync and metrics ports bind only to `127.0.0.1`; Nginx proxies authenticated client requests through `/powersync/`.

`setup-staging.sh` records the idempotent root provisioning steps used on this server; it reads the uploaded templates from the site's `shared/deploy-tools/powersync` directory. Provisioning requires Docker, PostgreSQL logical replication, a dedicated replication account with read access to the staging database, the `powersync` publication, and database connectivity from the private Docker subnet `172.28.77.0/24`. PostgreSQL must accept that account only through the appropriate private-network authentication rule. The database connection uses the private Docker network.

Install configuration under `/etc/equi-app/powersync` with root-only directory permissions. Copy `backend/powersync/sync_rules.yaml` alongside these templates. The two non-secret YAML files mounted into the non-root PowerSync container must be mode 644. Keep generated credentials in a mode-600 `runtime.env` containing `PS_DATA_SOURCE_URI`, `PS_MONGO_URI`, `PS_JWKS_URL`, and `PS_API_TOKEN`; never commit this file. Initialize MongoDB replica set `rs0` with member `mongo:27017` before starting PowerSync. Refresh the rules and restart PowerSync when sync rules change.

Verified on 2026-10-01: both containers are running, PostgreSQL uses `wal_level=logical`, the `equi_powersync` replication account and `powersync` publication are configured, the replication slot is active, and reported replication lag is zero. All 137 referenced media files are present at their expected sizes. Browser checks covered login, the 25-item catalog, synced article content, video playback, and back navigation; API checks covered allowed and denied library content. A direct PowerSync request subscribing to both an entitled item and a locked item delivered only the entitled content bucket.

Operate these services as root through the existing Ploi server script API:

```sh
cd /etc/equi-app/powersync
docker compose ps
docker compose logs --since=5m powersync
curl -fsS http://127.0.0.1:8080/probes/liveness
```

After deploying a release that changes sync rules, install the release's rules and restart the service:

```sh
install -m 644 /home/ploi/equi-app.online/current/powersync/sync_rules.yaml /etc/equi-app/powersync/sync_rules.yaml
cd /etc/equi-app/powersync
docker compose restart powersync
```

The containers use `restart: unless-stopped`, and Docker is enabled at boot. Credentials stay in `/etc/equi-app/powersync/runtime.env`; the directory remains mode 700. MongoDB is not published to a host port. Do not remove its Docker volume during routine deployments.
