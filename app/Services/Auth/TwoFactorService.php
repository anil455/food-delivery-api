<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP two-factor for staff and platform accounts.
 *
 * Setup is two steps on purpose. Generating a secret does not enable anything;
 * only a correct code from the authenticator confirms it. A user who scans the
 * QR and then loses the phone before confirming is not locked out, because an
 * unconfirmed secret never gates a login.
 */
final class TwoFactorService
{
    private const RECOVERY_CODE_COUNT = 8;

    /** One step either side of now, to tolerate modest clock drift. */
    private const WINDOW = 1;

    private Google2FA $totp;

    public function __construct()
    {
        $this->totp = new Google2FA;
    }

    /**
     * Issue an unconfirmed secret and the URI an authenticator app scans.
     *
     * Overwrites any previous unconfirmed secret, so an abandoned setup cannot
     * be resumed by someone else later.
     */
    public function beginSetup(User $user): array
    {
        $secret = $this->totp->generateSecretKey();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return [
            'secret' => $secret,
            'otpauth_url' => $this->totp->getQRCodeUrl(
                (string) config('app.name'),
                (string) $user->email,
                $secret,
            ),
        ];
    }

    /**
     * Confirm setup with a code from the authenticator, and hand back the
     * recovery codes. This is the only time they are ever shown.
     *
     * @return array<int, string>|null null when the code is wrong
     */
    public function confirm(User $user, string $code): ?array
    {
        if ($user->two_factor_secret === null || ! $this->verifyTotp($user, $code)) {
            return null;
        }

        $codes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_recovery_codes' => $codes,
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $codes;
    }

    /**
     * Check a login challenge: a TOTP code, or a single-use recovery code.
     *
     * A used recovery code is consumed inside a transaction, so the same code
     * cannot be spent twice by two concurrent requests.
     */
    public function verifyChallenge(User $user, string $code): bool
    {
        if (! $user->hasTwoFactorEnabled()) {
            return true;
        }

        if ($this->verifyTotp($user, $code)) {
            return true;
        }

        return $this->consumeRecoveryCode($user, $code);
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    /** @return array<int, string> a fresh set, invalidating the old one */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->generateRecoveryCodes();

        $user->forceFill(['two_factor_recovery_codes' => $codes])->save();

        return $codes;
    }

    private function verifyTotp(User $user, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        return $this->totp->verifyKey((string) $user->two_factor_secret, $code, self::WINDOW) !== false;
    }

    private function consumeRecoveryCode(User $user, string $candidate): bool
    {
        $candidate = strtoupper(trim($candidate));

        return DB::transaction(function () use ($user, $candidate): bool {
            $fresh = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            $codes = $fresh?->two_factor_recovery_codes ?? [];

            $index = array_search($candidate, array_map('strtoupper', $codes), true);

            if ($index === false) {
                return false;
            }

            unset($codes[$index]);

            $fresh->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();
            $user->setAttribute('two_factor_recovery_codes', array_values($codes));

            return true;
        });
    }

    /** @return array<int, string> */
    private function generateRecoveryCodes(): array
    {
        return collect(range(1, self::RECOVERY_CODE_COUNT))
            ->map(fn (): string => strtoupper(Str::random(5).'-'.Str::random(5)))
            ->all();
    }
}
