<?php

namespace App\Providers;

use App\Rules\RulePackRepository;
use App\Tenancy\TenantContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped: reset between requests/jobs under long-running workers (Octane, queue).
        $this->app->scoped(TenantContext::class);

        $this->app->singleton(RulePackRepository::class, fn () => new RulePackRepository(resource_path('rule-packs')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
