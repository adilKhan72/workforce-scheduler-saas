<?php

namespace Tests\Feature\GraphQL;

use App\Models\Location;
use App\Models\Shift;
use App\Models\StaffMember;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

abstract class GraphQLTestCase extends TestCase
{
    use MakesGraphQLRequests;

    protected Tenant $tenant;

    protected User $manager;

    protected Location $location;

    protected StaffMember $adult;

    protected StaffMember $minor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['name' => 'Northwind Coffee']);
        $this->manager = User::factory()->for($this->tenant)->create();

        $this->asTenant($this->tenant, function () {
            $this->location = Location::factory()->create(['name' => 'Brooklyn', 'timezone' => 'America/New_York', 'rule_pack' => 'us-ny']);
            $this->adult = StaffMember::factory()->create(['name' => 'Ava', 'skills' => ['barista', 'food_safety']]);
            $this->minor = StaffMember::factory()->minor('2011-01-01')->create(['name' => 'Ella']);
        });
    }

    /** @param array<string, mixed> $variables */
    protected function gql(string $query, array $variables = [], ?User $as = null): TestResponse
    {
        Sanctum::actingAs($as ?? $this->manager, ['*']);

        return $this->graphQL($query, $variables);
    }

    /** @param array<string, mixed> $attributes */
    protected function shift(array $attributes = [], ?Location $location = null): Shift
    {
        return $this->asTenant($this->tenant, fn () => Shift::factory()->create([
            'location_id' => ($location ?? $this->location)->id, ...$attributes,
        ]));
    }

    protected function otherTenant(): array
    {
        $tenant = Tenant::factory()->create(['name' => 'Harbour Diner']);
        $user = User::factory()->for($tenant)->create();

        return $this->asTenant($tenant, function () use ($tenant, $user) {
            $location = Location::factory()->create(['timezone' => 'Europe/London', 'rule_pack' => 'gb']);
            $staff = StaffMember::factory()->create();
            $shift = Shift::factory()->create(['location_id' => $location->id]);

            return compact('tenant', 'user', 'location', 'staff', 'shift');
        });
    }
}
