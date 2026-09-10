<?php

declare(strict_types=1);

namespace App\Services\Otp;

use App\Exceptions\Api\OtpException;
use App\Jobs\SendOtpJob;
use App\Models\OtpVerification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Generation, delivery and verification of one-time codes.
 *
 * Everything security-relevant about OTP lives here: the codes never leave this
 * class in plaintext except through an OtpChannel, and every threshold comes
 * from config so it can be tightened without a deploy.
 */
final class OtpService
{
    public function __construct(
        private readonly OtpRateLimiter $limiter,
    ) {}

    /**
     * Issue a code for a phone number.
     *
     * @param  string  $phone  E.164, already normalised by PhoneNumberService
     *
     * @throws OtpException when a cooldown or quota blocks the request
     */
    public function send(
        string $phone,
        string $purpose = 'login',
        ?string $ip = null,
        ?string $userAgent = null,
    ): OtpDispatchResult {
        $this->limiter->assertCanSend($phone, $ip);

        $code = $this->resolveCode($phone);
        $ttl = (int) config('otp.ttl_seconds');

        $verification = DB::transaction(function () use ($phone, $purpose, $code, $ttl, $ip, $userAgent) {
            // Any live code for this number dies the moment a new one is issued,
            // so a resend can never leave two valid codes in circulation.
            OtpVerification::query()
                ->forPhone($phone, $purpose)
                ->live()
                ->update(['consumed_at' => now()]);

            return OtpVerification::query()->create([
                'verification_id' => (string) Str::uuid(),
                'phone' => $phone,
                'purpose' => $purpose,
                'otp_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addSeconds($ttl),
                'ip_address' => $ip,
                'user_agent' => $userAgent ? Str::limit($userAgent, 250, '') : null,
            ]);
        });

        $this->limiter->recordSend($phone, $ip);

        SendOtpJob::dispatch($phone, $code, $purpose);

        return new OtpDispatchResult(
            verificationId: $verification->verification_id,
            expiresInSeconds: $ttl,
            resendAfterSeconds: (int) config('otp.resend_cooldown_seconds'),
            code: $this->exposedCode($code),
        );
    }

    /**
     * Check a submitted code.
     *
     * Lookup is by verification_id rather than phone: two concurrent send
     * requests for the same number would otherwise race over which row a verify
     * attempt lands on.
     *
     * @throws OtpException on every failure mode, each with its own error code
     */
    public function verify(string $verificationId, string $phone, string $code, ?string $ip = null): OtpVerification
    {
        $this->limiter->assertCanVerify($ip);
        $this->limiter->recordVerify($ip);

        $verification = OtpVerification::query()
            ->where('verification_id', $verificationId)
            ->first();

        if ($verification === null || $verification->phone !== $phone) {
            throw OtpException::notFound();
        }

        if ($verification->isConsumed()) {
            throw OtpException::notFound();
        }

        if ($verification->isExpired()) {
            throw OtpException::expired();
        }

        if (! $verification->hasAttemptsLeft()) {
            $verification->consume();

            throw OtpException::maxAttempts();
        }

        if (! Hash::check($code, $verification->otp_hash)) {
            $verification->increment('attempts');

            // Burn the challenge the moment the budget runs out, so an attacker
            // gets exactly max_attempts guesses per issued code and no more.
            if (! $verification->fresh()->hasAttemptsLeft()) {
                $verification->consume();

                throw OtpException::maxAttempts();
            }

            throw OtpException::invalid();
        }

        $verification->consume();

        return $verification;
    }

    /**
     * Fixed codes for QA handsets, honoured only outside production and only
     * when the operator has listed the number explicitly.
     */
    private function resolveCode(string $phone): string
    {
        if (! app()->environment('production')) {
            foreach ((array) config('otp.test_numbers') as $entry) {
                [$testPhone, $testCode] = array_pad(explode(':', (string) $entry, 2), 2, null);

                if ($testPhone === $phone && $testCode !== null) {
                    return $testCode;
                }
            }
        }

        return $this->generateCode();
    }

    private function generateCode(): string
    {
        $length = (int) config('otp.length');
        $max = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }

    /**
     * The first half of the double gate. config/otp.php holds the flag;
     * AppServiceProvider refuses to boot production with it enabled. Both must
     * agree before a code is ever echoed back to a caller.
     */
    private function exposedCode(string $code): ?string
    {
        if (config('otp.expose_in_response') === true && app()->environment('local')) {
            return $code;
        }

        return null;
    }
}
