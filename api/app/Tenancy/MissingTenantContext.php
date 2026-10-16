<?php

namespace App\Tenancy;

use LogicException;

final class MissingTenantContext extends LogicException
{
    public static function forWrite(string $model): self
    {
        return new self("Cannot write [{$model}] without a tenant context.");
    }

    public static function crossTenantWrite(string $model): self
    {
        return new self("Refusing to write [{$model}] for a tenant other than the current one.");
    }
}
