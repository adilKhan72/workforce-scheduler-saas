<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Application-level tenant isolation: the primary control. RLS is the backstop (ADR-0001).
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::saving(function (Model $model): void {
            $current = app(TenantContext::class)->id();

            if ($current === null) {
                throw MissingTenantContext::forWrite($model::class);
            }

            if ($model->getAttribute('tenant_id') === null) {
                $model->setAttribute('tenant_id', $current);
            } elseif ($model->getAttribute('tenant_id') !== $current) {
                throw MissingTenantContext::crossTenantWrite($model::class);
            }
        });

        static::deleting(function (Model $model): void {
            if ($model->getAttribute('tenant_id') !== app(TenantContext::class)->id()) {
                throw MissingTenantContext::crossTenantWrite($model::class);
            }
        });
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
