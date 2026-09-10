<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Services\Auth\TwoFactorService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;

/**
 * Two-factor setup for the signed-in staff account.
 *
 * Every state-changing call here re-checks the password, because a stolen
 * bearer token must not be enough to disable a second factor or to mint a new
 * set of recovery codes.
 */
class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return ApiResponse::success(
            data: [
                'enabled' => $user->hasTwoFactorEnabled(),
                'pending_confirmation' => $user->two_factor_secret !== null && ! $user->hasTwoFactorEnabled(),
                'confirmed_at' => $user->two_factor_confirmed_at?->toIso8601String(),
                'recovery_codes_remaining' => count($user->two_factor_recovery_codes ?? []),
            ],
            message: 'Two-factor status fetched successfully',
        );
    }

    /**
     * Step one: issue a secret and the URI an authenticator app scans.
     * Nothing is enabled until confirm() succeeds.
     */
    public function setup(Request $request): JsonResponse
    {
        $this->assertPassword($request);

        $setup = $this->twoFactor->beginSetup($request->user());

        return ApiResponse::success(
            data: [
                'secret' => $setup['secret'],
                'otpauth_url' => $setup['otpauth_url'],
                'next' => 'Scan the URL, then POST the 6-digit code to /admin/auth/2fa/confirm.',
            ],
            message: 'Scan this in your authenticator app',
        );
    }

    /** Step two: prove the authenticator works, and receive the recovery codes. */
    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:10'],
        ]);

        $codes = $this->twoFactor->confirm($request->user(), $validated['code']);

        if ($codes === null) {
            return ApiResponse::error(
                message: 'That code did not match. Check your authenticator and try again.',
                code: ApiErrorCode::TwoFactorInvalid,
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return ApiResponse::success(
            data: [
                'enabled' => true,
                // Shown exactly once. There is no endpoint that returns them again.
                'recovery_codes' => $codes,
                'warning' => 'Store these now. They are shown only once and each works a single time.',
            ],
            message: 'Two-factor authentication is on',
        );
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $this->assertPassword($request);

        $user = $request->user();

        if (! $user->hasTwoFactorEnabled()) {
            return ApiResponse::error(
                message: 'Two-factor authentication is not enabled on this account.',
                code: ApiErrorCode::Conflict,
                status: Response::HTTP_CONFLICT,
            );
        }

        return ApiResponse::success(
            data: [
                'recovery_codes' => $this->twoFactor->regenerateRecoveryCodes($user),
                'warning' => 'Your previous recovery codes no longer work.',
            ],
            message: 'Recovery codes regenerated',
        );
    }

    public function disable(Request $request): JsonResponse
    {
        $this->assertPassword($request);

        $this->twoFactor->disable($request->user());

        return ApiResponse::success(message: 'Two-factor authentication is off');
    }

    /**
     * Re-authentication for sensitive changes.
     *
     * Aborts rather than returning, so no caller can forget to check the result.
     */
    private function assertPassword(Request $request): void
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = $request->user();

        abort_if(
            $user->password === null || ! Hash::check($validated['password'], $user->password),
            Response::HTTP_FORBIDDEN,
            'Your password is incorrect.',
        );
    }
}
