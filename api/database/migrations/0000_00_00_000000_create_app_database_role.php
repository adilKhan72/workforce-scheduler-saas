<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The API must connect as a role that is neither superuser nor table owner, because both bypass
 * row-level security. Migrations run as the owner; this grants the runtime role DML only.
 *
 * In docker compose the role already exists as a LOGIN role (docker/postgres/init.sql). In CI and
 * tests it is created here as NOLOGIN and the test suite switches to it with SET ROLE.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $role = Rls::appRole();

        DB::statement(<<<SQL
            DO \$\$
            BEGIN
                IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '{$role}') THEN
                    CREATE ROLE "{$role}" NOLOGIN NOSUPERUSER NOBYPASSRLS;
                END IF;
            END
            \$\$
        SQL);

        DB::statement("GRANT USAGE ON SCHEMA public TO \"{$role}\"");
        // Applies to every table/sequence created by the migrating role from now on.
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO \"{$role}\"");
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO \"{$role}\"");
    }

    public function down(): void
    {
        // Roles are cluster-wide and may be shared with other databases; never dropped by a migration.
    }
};
