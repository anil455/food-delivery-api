<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserType;
use App\Models\OtpVerification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VerifyOtpTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '+919999000011';

    private const CODE = '123456';

    protected function setUp(): void
    {
        parent::setUp();

        // A fixed code for this handset, honoured only outside production.
        Config::set('otp.test_numbers', [self::PHONE.':'.self::CODE]);
    }

    private function requestCode(string $phone = self::PHONE): string
    {
        return $this->postJson('/api/v1/auth/otp/send', ['phone' => $phone])
            ->json('data.verification_id');
    }

    #[Test]
    public function a_correct_code_creates_the_account_and_returns_a_token(): void
    {
        $verificationId = $this->requestCode();

        $response = $this->postJson('/api/v1/auth/otp/verify', [
            'verification_id' => $verificationId,
            'phone' => self::PHONE,
            'otp' => self::CODE,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.phone', self::PHONE)
            ->assertJsonPath('data.user.type', 'customer')
            ->assertJsonStructure(['data' => ['token', 'expires_at', 'user' => ['id', 'phone']]]);

        $user = User::query()->where('phone', self::PHONE)->sole();

        $this->assertSame(UserType::Customer, $user->type);
        $this->assertNotNull($user->phone_verified_at);
        $this->assertNotNull($user->last_login_at);
    }

    #[Test]
    public function the_issued_token_carries_only_the_customer_ability(): void
    {
        $verificationId = $this->requestCode();

        $this->postJson('/api/v1/auth/otp/verify', [
            'verification_id' => $verificationId,
            'phone' => self::PHONE,
            'otp' => self::CODE,
        ])->assertOk();

        $token = User::query()->where('phone', self::PHONE)->sole()->tokens()->sole();

        $this->assertSame(['customer'], $token->abilities);
        $this->assertNotNull($token->expires_at);
    }

    #[Test]
    public function an_existing_customer_logs_in_without_being_duplicated(): void
    {
        $existing = User::factory()->create(['phone' => self::PHONE, 'name' => 'Aarav']);

        $verificationId = $this->requestCode();

        $this->postJson('/api/v1/auth/otp/verify', [
            'verification_id' => $verificationId,
            'phone' => self::PHONE,
            'otp' => self::CODE,
        ])->assertOk()->assertJsonPath('data.user.id', $existing->id);

        $this->assertSame(1, User::query()->where('phone', self::PHONE)->count());
    }

    #[Test]
    public function a_wrong_code_is_rejected_and_counted(): void
    {
        $verificationId = $this->requestCode();

        $this->postJson('/api/v1/auth/otp/verify', [
            'verification_id' => $verificationId,
            'phone' => self::PHONE,
            'otp' => '000000',
        ])->assertStatus(422)->assertJsonPath('code', 'OTP_INVALID');

        $this->assertSame(1, OtpVerification::query()->where('verification_id', $verificationId)->sole()->attempts);
        $this->assertDatabaseCount('users', 0);
    }

    #[Test]
    public function the_code_dies_once_the_attempt_budget_is_spent(): void
    {
        Config::set('otp.max_attempts', 3);

        $verificationId = $this->requestCode();

        // Two wrong guesses are simply invalid.
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/v1/auth/otp/verify', [
                'verification_id' => $verificationId,
                'phone' => self::PHONE,
                'otp' => '000000',
            ])->assertJsonPath('code', 'OTP_INVALID');
        }

        // The third exhausts the budget and burns the challenge.
        $this->postJson('/api/v1/auth/otp/verify', [
            'verification_id' => $verificationId,
            'phone' => self::PHONE,
            'otp' => '000000',
        ])->assertStatus(422)->assertJsonPath('code', 'OTP_MAX_ATTEMPTS');

        // Even the correct code cannot revive it.
        $this->postJson('/api/v1/auth/otp/verify', [
            'verification_id' => $verificationId,
            'phone' => self::PHONE,
            'otp' => self::CODE,
        ])->assertStatus(422)->assertJsonPath('code', 'OTP_NOT_FOUND');

        $this->assertDatabaseCount('users', 0);
    }

    #[Test]
    public function an_expired_code_is_rejected(): void
    {
        $verificationId = $this->requestCode();

        $this->travel((int) config('otp.ttl_seconds') + 5)->seconds();

        $this->postJson('/api/v1/auth/otp/verify', [
            'verification_id' => $verificationId,
            'phone' => self::PHONE,
            'otp' => self::CODE,
        ])->assertStatus(422)->assertJsonPath('code', 'OTP_EXPIRED');
    }

    #[Test]
    public function a_code_cannot_be_used_twice(): void
    {
        $verificationId = $this->requestCode();

        $payload = [
            'verification_id' => $verificationId,
            'phone' => self::PHONE,
            'otp' => self::CODE,
        ];

        $this->postJson('/api/v1/auth/otp/verify', $payload)->assertOk();
        $this->postJson('/api/v1/auth/otp/verify', $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'OTP_NOT_FOUND');
    }

    #[Test]
    public function a_handle_cannot_be_redeemed_against_a_different_phone(): void
    {
        $verificationId = $this->requestCode();

        // Binding the handle to its phone stops a caller from redeeming someone
        // else's live challenge by swapping the number.
        $this->postJson('/api/v1/auth/otp/verify', [
            'verification_id' => $verificationId,
            'phone' => '+919999000022',
            'otp' => self::CODE,
        ])->assertStatus(422)->assertJsonPath('code', 'OTP_NOT_FOUND');
    }

    #[Test]
    public function an_unknown_handle_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/otp/verify', [
            'verification_id' => '00000000-0000-4000-8000-000000000000',
            'phone' => self::PHONE,
            'otp' => self::CODE,
        ])->assertStatus(422)->assertJsonPath('code', 'OTP_NOT_FOUND');
    }

    #[Test]
    public function a_blocked_account_cannot_log_in(): void
    {
        User::factory()->blocked()->create(['phone' => self::PHONE]);

        $verificationId = $this->requestCode();

        $this->postJson('/api/v1/auth/otp/verify', [
            'verification_id' => $verificationId,
            'phone' => self::PHONE,
            'otp' => self::CODE,
        ])->assertStatus(403)->assertJsonPath('code', 'ACCOUNT_DISABLED');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[Test]
    public function it_validates_the_payload_shape(): void
    {
        $this->postJson('/api/v1/auth/otp/verify', [
            'verification_id' => 'not-a-uuid',
            'phone' => self::PHONE,
            'otp' => '12',
        ])->assertStatus(422)->assertJsonValidationErrors(['verification_id', 'otp']);
    }
}
