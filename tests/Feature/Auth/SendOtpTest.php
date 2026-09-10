<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\OtpVerification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SendOtpTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '+919999000011';

    #[Test]
    public function it_issues_a_verification_handle(): void
    {
        $response = $this->postJson('/api/v1/auth/otp/send', ['phone' => self::PHONE]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'message', 'data' => ['verification_id', 'expires_in', 'resend_after']]);

        $this->assertDatabaseCount('otp_verifications', 1);
    }

    #[Test]
    public function it_never_returns_the_code_outside_local_development(): void
    {
        // The environment is `testing`, so even with the flag on the double gate
        // must keep the code out of the response.
        Config::set('otp.expose_in_response', true);

        $response = $this->postJson('/api/v1/auth/otp/send', ['phone' => self::PHONE]);

        $response->assertOk();
        $this->assertArrayNotHasKey('otp', $response->json('data'));
    }

    #[Test]
    public function it_stores_the_code_hashed_rather_than_in_plaintext(): void
    {
        Config::set('otp.test_numbers', [self::PHONE.':123456']);

        $this->postJson('/api/v1/auth/otp/send', ['phone' => self::PHONE])->assertOk();

        $record = OtpVerification::query()->sole();

        $this->assertNotSame('123456', $record->otp_hash);
        $this->assertTrue(password_verify('123456', $record->otp_hash));
    }

    #[Test]
    public function it_normalises_phone_numbers_to_e164(): void
    {
        // All three spellings of the same handset must land on one record.
        foreach (['9999000011', '09999000011', '+91 99990 00011'] as $index => $spelling) {
            $this->travel($index * 120)->seconds();

            $this->postJson('/api/v1/auth/otp/send', ['phone' => $spelling])->assertOk();
        }

        $this->assertSame(
            [self::PHONE],
            OtpVerification::query()->distinct()->pluck('phone')->all(),
        );
    }

    #[Test]
    public function it_rejects_an_unparseable_number(): void
    {
        $this->postJson('/api/v1/auth/otp/send', ['phone' => '12345'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors('phone');
    }

    #[Test]
    public function it_requires_a_phone_number(): void
    {
        $this->postJson('/api/v1/auth/otp/send', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    #[Test]
    public function a_resend_inside_the_cooldown_is_refused(): void
    {
        $this->postJson('/api/v1/auth/otp/send', ['phone' => self::PHONE])->assertOk();

        $this->postJson('/api/v1/auth/otp/send', ['phone' => self::PHONE])
            ->assertStatus(429)
            ->assertJsonPath('code', 'OTP_RESEND_COOLDOWN')
            ->assertJsonStructure(['data' => ['retry_after']]);
    }

    #[Test]
    public function a_new_code_kills_the_previous_one(): void
    {
        $first = $this->postJson('/api/v1/auth/otp/send', ['phone' => self::PHONE])->json('data.verification_id');

        $this->travel(61)->seconds();

        $second = $this->postJson('/api/v1/auth/otp/send', ['phone' => self::PHONE])->json('data.verification_id');

        $this->assertNotSame($first, $second);

        // Two live codes for one number would double an attacker's guessing budget.
        $this->assertNotNull(
            OtpVerification::query()->where('verification_id', $first)->sole()->consumed_at,
        );
        $this->assertNull(
            OtpVerification::query()->where('verification_id', $second)->sole()->consumed_at,
        );
    }

    #[Test]
    public function the_response_does_not_reveal_whether_the_account_exists(): void
    {
        User::factory()->create(['phone' => self::PHONE]);

        $known = $this->postJson('/api/v1/auth/otp/send', ['phone' => self::PHONE]);

        $this->travel(61)->seconds();

        $unknown = $this->postJson('/api/v1/auth/otp/send', ['phone' => '+919999000022']);

        $this->assertSame(
            array_keys($known->json('data')),
            array_keys($unknown->json('data')),
        );
        $this->assertSame($known->json('message'), $unknown->json('message'));
        $this->assertSame($known->status(), $unknown->status());
    }

    #[Test]
    public function the_per_phone_hourly_quota_is_enforced(): void
    {
        Config::set('otp.per_phone_hourly', 3);

        for ($i = 0; $i < 3; $i++) {
            $this->travel(61)->seconds();
            $this->postJson('/api/v1/auth/otp/send', ['phone' => self::PHONE])->assertOk();
        }

        $this->travel(61)->seconds();

        $this->postJson('/api/v1/auth/otp/send', ['phone' => self::PHONE])
            ->assertStatus(429)
            ->assertJsonPath('code', 'TOO_MANY_REQUESTS');
    }
}
