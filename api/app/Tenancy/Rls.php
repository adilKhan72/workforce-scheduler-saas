<?php

namespace App\Tenancy;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * PostgreSQL row-level security helpers used by migrations (ADR-0001).
 *
 * Every tenant-owned table gets ENABLE + FORCE row level security and one policy that compares
 * tenant_id with the transaction-local setting app.tenant_id. An unset setting yields NULL, so
 * the policy matches nothing (fail closed).
 */
final class Rls
{
    public const SETTING = 'app.tenant_id';

    public static function appRole(): string
    {
        $role = (string) config('database.app_role');

        if (! preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role)) {
            throw new InvalidArgumentException("Invalid database.app_role [{$role}].");
        }

        return $role;
    }

    public static function enable(string $table): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $predicate = "tenant_id = nullif(current_setting('".self::SETTING."', true), '')::uuid";

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
        DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$predicate}) WITH CHECK ({$predicate})");
    }
}
