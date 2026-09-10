<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SessionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function me_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    #[Test]
    public function me_returns_the_authenticated_profile(): void
    {
        $user = User::factory()->create(['name' => 'Aarav Kapoor']);
        Sanctum::actingAs($user, ['customer']);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', 'Aarav Kapoor')
            ->assertJsonPath('data.type', 'customer');
    }

    #[Test]
    public function the_profile_never_exposes_the_password_hash(): void
    {
        Sanctum::actingAs(User::factory()->staff()->create(), ['restaurant']);

        $data = $this->getJson('/api/v1/auth/me')->assertOk()->json('data');

        $this->assertArrayNotHasKey('password', $data);
        $this->assertArrayNotHasKey('remember_token', $data);
    }

    #[Test]
    public function a_customer_can_update_their_own_name_and_email(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['customer']);

        $this->patchJson('/api/v1/auth/me', [
            'name' => 'Updated Name',
            'email' => 'aarav@example.test',
        ])->assertOk()->assertJsonPath('data.name', 'Updated Name');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'aarav@example.test']);
    }

    #[Test]
    public function privilege_columns_cannot_be_set_through_the_profile_endpoint(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['customer']);

        // Mass-assignment guard plus validated() means these are simply dropped.
        $this->patchJson('/api/v1/auth/me', [
            'name' => 'Legit',
            'is_super_admin' => true,
            'type' => 'staff',
            'status' => 'active',
        ])->assertOk();

        $user->refresh();

        $this->assertFalse($user->is_super_admin);
        $this->assertSame('customer', $user->type->value);
    }

    #[Test]
    public function logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create();

        $keep = $user->createToken('other-device', ['customer'])->plainTextToken;
        $current = $user->createToken('this-device', ['customer'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$current)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertSame(1, $user->tokens()->count());

        // The container is shared across requests inside one test method, and
        // the guard caches the user it resolved first. Forgetting the guards
        // makes each of the following requests authenticate from scratch, which
        // is what a real second HTTP request would do.
        $this->app['auth']->forgetGuards();

        // The other device stays signed in.
        $this->withHeader('Authorization', 'Bearer '.$keep)
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        $this->app['auth']->forgetGuards();

        // The revoked one does not.
        $this->withHeader('Authorization', 'Bearer '.$current)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    }

    #[Test]
    public function logout_all_revokes_every_token(): void
    {
        $user = User::factory()->create();

        $user->createToken('device-a', ['customer']);
        $current = $user->createToken('device-b', ['customer'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$current)
            ->postJson('/api/v1/auth/logout-all')
            ->assertOk();

        $this->assertSame(0, $user->tokens()->count());
    }
}
