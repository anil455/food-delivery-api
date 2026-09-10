<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->staff()->create([
            'email' => 'owner@test.local',
            'password' => 'correct-horse-battery',
        ]);
    }

    private function actingAsStaff(): void
    {
        app('auth')->forgetGuards();
        Sanctum::actingAs($this->staff, ['restaurant']);
    }

    private function currentCode(User $user): string
    {
        return (new Google2FA)->getCurrentOtp($user->fresh()->two_factor_secret);
    }

    /** Runs the full two-step setup and returns the recovery codes. */
    private function enableTwoFactor(): array
    {
        $this->actingAsStaff();

        $this->postJson('/api/v1/admin/auth/2fa/setup', ['password' => 'correct-horse-battery'])
            ->assertOk();

        return $this->postJson('/api/v1/admin/auth/2fa/confirm', [
            'code' => $this->currentCode($this->staff),
        ])->assertOk()->json('data.recovery_codes');
    }

    #[Test]
    public function setup_returns_a_secret_and_an_authenticator_url(): void
    {
        $this->actingAsStaff();

        $data = $this->postJson('/api/v1/admin/auth/2fa/setup', ['password' => 'correct-horse-battery'])
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($data['secret']);
        $this->assertStringStartsWith('otpauth://totp/', $data['otpauth_url']);

        // A generated secret enables nothing on its own.
        $this->assertFalse($this->staff->fresh()->hasTwoFactorEnabled());
    }

    #[Test]
    public function setup_requires_the_current_password(): void
    {
        $this->actingAsStaff();

        // A stolen bearer token alone must not be enough to touch the second factor.
        $this->postJson('/api/v1/admin/auth/2fa/setup', ['password' => 'wrong'])
            ->assertStatus(403);
    }

    #[Test]
    public function a_wrong_code_does_not_confirm_setup(): void
    {
        $this->actingAsStaff();
        $this->postJson('/api/v1/admin/auth/2fa/setup', ['password' => 'correct-horse-battery'])->assertOk();

        $this->postJson('/api/v1/admin/auth/2fa/confirm', ['code' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'TWO_FACTOR_INVALID');

        $this->assertFalse($this->staff->fresh()->hasTwoFactorEnabled());
    }

    #[Test]
    public function confirming_enables_it_and_returns_recovery_codes_once(): void
    {
        $codes = $this->enableTwoFactor();

        $this->assertCount(8, $codes);
        $this->assertTrue($this->staff->fresh()->hasTwoFactorEnabled());

        // There is no endpoint that returns them again.
        $status = $this->getJson('/api/v1/admin/auth/2fa')->assertOk()->json('data');
        $this->assertTrue($status['enabled']);
        $this->assertSame(8, $status['recovery_codes_remaining']);
        $this->assertArrayNotHasKey('recovery_codes', $status);
    }

    #[Test]
    public function login_without_a_code_returns_a_challenge_not_a_token(): void
    {
        $this->enableTwoFactor();
        app('auth')->forgetGuards();

        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'owner@test.local',
            'password' => 'correct-horse-battery',
        ])->assertStatus(403);

        $response->assertJsonPath('code', 'TWO_FACTOR_REQUIRED')
            ->assertJsonPath('data.two_factor_required', true);

        $this->assertArrayNotHasKey('token', $response->json('data'));
    }

    #[Test]
    public function login_succeeds_with_a_valid_authenticator_code(): void
    {
        $this->enableTwoFactor();
        app('auth')->forgetGuards();

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'owner@test.local',
            'password' => 'correct-horse-battery',
            'two_factor_code' => $this->currentCode($this->staff),
        ])->assertOk()->assertJsonPath('data.token_type', 'Bearer');
    }

    #[Test]
    public function a_wrong_code_at_login_issues_nothing(): void
    {
        $this->enableTwoFactor();
        app('auth')->forgetGuards();

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'owner@test.local',
            'password' => 'correct-horse-battery',
            'two_factor_code' => '000000',
        ])->assertStatus(422)->assertJsonPath('code', 'TWO_FACTOR_INVALID');

        // A half-completed sign-in leaves no trace of success.
        $this->assertNull($this->staff->fresh()->last_login_at);
        $this->assertSame(0, $this->staff->tokens()->count());
    }

    #[Test]
    public function a_recovery_code_works_once_and_is_then_spent(): void
    {
        $codes = $this->enableTwoFactor();
        app('auth')->forgetGuards();

        $payload = [
            'email' => 'owner@test.local',
            'password' => 'correct-horse-battery',
            'two_factor_code' => $codes[0],
        ];

        $this->postJson('/api/v1/admin/auth/login', $payload)->assertOk();

        app('auth')->forgetGuards();

        // Single use: the same code cannot be replayed.
        $this->postJson('/api/v1/admin/auth/login', $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'TWO_FACTOR_INVALID');

        $this->assertCount(7, $this->staff->fresh()->two_factor_recovery_codes);
    }

    #[Test]
    public function regenerating_recovery_codes_invalidates_the_old_set(): void
    {
        $original = $this->enableTwoFactor();

        $fresh = $this->postJson('/api/v1/admin/auth/2fa/recovery-codes', [
            'password' => 'correct-horse-battery',
        ])->assertOk()->json('data.recovery_codes');

        $this->assertCount(8, $fresh);
        $this->assertEmpty(array_intersect($original, $fresh));

        app('auth')->forgetGuards();

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'owner@test.local',
            'password' => 'correct-horse-battery',
            'two_factor_code' => $original[0],
        ])->assertStatus(422);
    }

    #[Test]
    public function it_can_be_disabled_with_the_password(): void
    {
        $this->enableTwoFactor();

        $this->deleteJson('/api/v1/admin/auth/2fa', ['password' => 'correct-horse-battery'])->assertOk();

        $this->assertFalse($this->staff->fresh()->hasTwoFactorEnabled());

        app('auth')->forgetGuards();

        // Back to password-only.
        $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'owner@test.local',
            'password' => 'correct-horse-battery',
        ])->assertOk();
    }

    #[Test]
    public function the_secret_is_encrypted_at_rest_and_never_serialised(): void
    {
        $this->enableTwoFactor();

        $raw = \Illuminate\Support\Facades\DB::table('users')
            ->where('id', $this->staff->id)
            ->value('two_factor_secret');

        // A leaked database row must not hand over a working seed.
        $this->assertNotSame($this->staff->fresh()->two_factor_secret, $raw);

        $profile = $this->getJson('/api/v1/admin/auth/me')->assertOk()->json('data.user');
        $this->assertArrayNotHasKey('two_factor_secret', $profile);
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $profile);
    }

    #[Test]
    public function accounts_without_two_factor_are_unaffected(): void
    {
        $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'owner@test.local',
            'password' => 'correct-horse-battery',
        ])->assertOk();
    }
}
