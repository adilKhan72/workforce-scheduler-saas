<?php

namespace Tests\Feature\Tenancy;

use App\Models\Location;
use App\Models\Tenant;
use App\Models\User;
use Tests\TestCase;

final class AuthenticatedTenantTest extends TestCase
{
    public function test_login_returns_a_token_bound_to_the_users_tenant(): void
    {
        $user = User::factory()->create(['email' => 'manager@a.test']);

        $this->postJson('/api/login', ['email' => 'manager@a.test', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('tenant.id', $user->tenant_id)
            ->assertJsonStructure(['token']);
    }

    public function test_wrong_password_is_rejected(): void
    {
        User::factory()->create(['email' => 'manager@a.test']);

        $this->postJson('/api/login', ['email' => 'manager@a.test', 'password' => 'nope'])->assertStatus(422);
    }

    public function test_the_tenant_comes_from_the_token_not_from_the_request(): void
    {
        [$a, $b] = Tenant::factory()->count(2)->create();
        $this->asTenant($a, fn () => Location::factory()->count(1)->create());
        $this->asTenant($b, fn () => Location::factory()->count(4)->create());
        $user = User::factory()->for($a)->create();
        $token = $user->createToken('t')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/me?tenant_id='.$b->id, ['X-Tenant-Id' => $b->id])
            ->assertOk()
            ->assertJsonPath('tenant.id', $a->id)
            ->assertJsonPath('counts.locations', 1);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
    }
}
