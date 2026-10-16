#!/bin/sh
# Runs once, when the postgres volume is first initialised.
# The API connects as this LOGIN role: not superuser, not table owner, so RLS applies (ADR-0001).
# Table grants come from the migrations (ALTER DEFAULT PRIVILEGES), which run as the owner.
set -eu
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<SQL
CREATE ROLE scheduler_app LOGIN PASSWORD '${APP_DB_PASSWORD}' NOSUPERUSER NOBYPASSRLS;
GRANT CONNECT ON DATABASE "${POSTGRES_DB}" TO scheduler_app;
SQL
