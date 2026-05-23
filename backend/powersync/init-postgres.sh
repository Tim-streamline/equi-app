#!/usr/bin/env bash
# Creates the role and publication PowerSync needs to consume the Postgres
# write-ahead log. Runs once on a fresh data volume via docker-entrypoint.
# Re-run manually on existing volumes:
#   docker compose exec pgsql psql -U sail -d laravel -f /docker-entrypoint-initdb.d/20-powersync.sh
set -euo pipefail

: "${POWERSYNC_PG_PASSWORD:=powersync_password}"

psql --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-SQL
    DO \$\$
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'powersync_role') THEN
            CREATE ROLE powersync_role
                WITH REPLICATION BYPASSRLS LOGIN
                PASSWORD '${POWERSYNC_PG_PASSWORD}';
        END IF;
    END
    \$\$;

    GRANT SELECT ON ALL TABLES IN SCHEMA public TO powersync_role;
    ALTER DEFAULT PRIVILEGES IN SCHEMA public
        GRANT SELECT ON TABLES TO powersync_role;

    -- The publication name 'powersync' is required by PowerSync.
    DO \$\$
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM pg_publication WHERE pubname = 'powersync') THEN
            CREATE PUBLICATION powersync FOR ALL TABLES;
        END IF;
    END
    \$\$;
SQL
