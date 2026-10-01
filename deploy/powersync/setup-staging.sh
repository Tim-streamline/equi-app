#!/bin/bash
set -euo pipefail
umask 077
site=/home/ploi/equi-app.online
config=/etc/equi-app/powersync
mkdir -p "$config"
chmod 700 "$config"
install -m 600 "$site/shared/deploy-tools/powersync/compose.yaml" "$config/compose.yaml"
install -m 644 "$site/shared/deploy-tools/powersync/service.yaml" "$config/service.yaml"
install -m 644 "$site/shared/deploy-tools/powersync/sync_rules.yaml" "$config/sync_rules.yaml"
if [[ ! -s "$config/runtime.env" ]]; then
python3 - <<'PY'
import pathlib,secrets
password=secrets.token_hex(32)
a=pathlib.Path('/etc/equi-app/powersync')
(a/'runtime.env').write_text('PS_DATA_SOURCE_URI=postgresql://equi_powersync:'+password+'@host.docker.internal:5432/equi_app_staging\nPS_MONGO_URI=mongodb://mongo:27017/powersync?replicaSet=rs0\nPS_JWKS_URL=https://equi-app.online/.well-known/jwks.json\nPS_API_TOKEN='+secrets.token_hex(32)+'\n')
(a/'role.sql').write_text("CREATE ROLE equi_powersync WITH REPLICATION LOGIN PASSWORD '"+password+"';\n")
PY
runuser -u postgres -- psql -v ON_ERROR_STOP=1 -d equi_app_staging < "$config/role.sql"
rm "$config/role.sql"
fi
runuser -u postgres -- psql -v ON_ERROR_STOP=1 -d equi_app_staging <<'SQL'
GRANT CONNECT ON DATABASE equi_app_staging TO equi_powersync;
GRANT USAGE ON SCHEMA public TO equi_powersync;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO equi_powersync;
ALTER DEFAULT PRIVILEGES FOR ROLE equi_app_staging IN SCHEMA public GRANT SELECT ON TABLES TO equi_powersync;
DO $$ BEGIN IF NOT EXISTS(SELECT 1 FROM pg_publication WHERE pubname='powersync') THEN CREATE PUBLICATION powersync FOR ALL TABLES; END IF; END $$;
SQL
if ! grep -q '# EquiApp PowerSync' /etc/postgresql/18/main/pg_hba.conf; then
cp -p /etc/postgresql/18/main/pg_hba.conf "$config/pg_hba.before"
{ echo '# EquiApp PowerSync private Docker network'; echo 'host equi_app_staging equi_powersync 172.28.77.0/24 scram-sha-256'; cat "$config/pg_hba.before"; } > /etc/postgresql/18/main/pg_hba.conf
fi
ufw allow from 172.28.77.0/24 to 172.17.0.1 port 5432 proto tcp
if [[ "$(runuser -u postgres -- psql -Atc 'show wal_level')" != logical ]]; then
runuser -u postgres -- psql -v ON_ERROR_STOP=1 -c "ALTER SYSTEM SET wal_level='logical'"
pg_ctlcluster 18 main restart
else
pg_ctlcluster 18 main reload
fi
cd "$config"
docker compose up -d mongo
for i in {1..45}; do
  if docker compose exec -T mongo mongosh --quiet --eval 'db.adminCommand({ping:1}).ok || quit(1)' >/dev/null 2>&1; then break; fi
  sleep 2
done
docker compose exec -T mongo mongosh --quiet --eval 'try { rs.status() } catch(e) { rs.initiate({_id:"rs0",members:[{_id:0,host:"mongo:27017"}]}) }'
docker compose up -d powersync
docker compose ps
