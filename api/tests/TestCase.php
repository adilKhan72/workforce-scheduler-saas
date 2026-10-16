<?php

namespace Tests;

use App\Models\Tenant;
use App\Tenancy\Rls;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Migrations ran as the owner. Everything a test does from here runs as the runtime role,
        // which is subject to RLS. SET ROLE is undone when RefreshDatabase rolls back the
        // per-test transaction.
        if ($this->onPostgres()) {
            DB::statement('SET ROLE "'.Rls::appRole().'"');
        }
    }

    protected function onPostgres(): bool
    {
        return DB::getDriverName() === 'pgsql';
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function asTenant(Tenant $tenant, Closure $callback): mixed
    {
        return app(TenantContext::class)->run($tenant, $callback);
    }
}
