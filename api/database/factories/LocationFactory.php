<?php

namespace Database\Factories;

use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tenant-owned: create inside TenantContext::run(); tenant_id is filled from the context.
 *
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->city().' store',
            'timezone' => 'America/New_York',
            'rule_pack' => 'us-ny',
        ];
    }
}
