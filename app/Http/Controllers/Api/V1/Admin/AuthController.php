<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ApiErrorCode;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\UserResource;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;

/**
 * Staff and super-admin sign in with email and password.
 *
 * Customers cannot reach this endpoint even with correct credentials, because a
 * customer has no password and the user type is checked explicitly.
 */
class AuthController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'two_factor_code' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);

        $user = User::query()
            ->where('email', $validated['email'])
            ->where('type', UserType::Staff->value)
            ->first();

        // One message and one status for every failure mode, so the response
        // never reveals whether an email exists.
        if ($user === null || $user->password === null || ! Hash::check($validated['password'], $user->password)) {
            return ApiResponse::error(
                message: 'These credentials do not match our records.',
                code: ApiErrorCode::Unauthenticated,
                status: Response::HTTP_UNAUTHORIZED,
            );
        }

        if ($user->status !== 'active') {
            return ApiResponse::error(
                message: 'This account has been disabled.',
                code: ApiErrorCode::AccountDisabled,
                status: Response::HTTP_FORBIDDEN,
            );
        }

        /*
         * The second factor is checked after the password, and before anything
         * is issued or recorded. A correct password with no code returns a
         * challenge rather than a token, and last_login_at is left alone so a
         * half-completed sign-in leaves no trace of success.
         */
        if ($user->hasTwoFactorEnabled()) {
            $code = $validated['two_factor_code'] ?? null;

            if (blank($code)) {
                return ApiResponse::error(
                    message: 'Enter the code from your authenticator app.',
                    code: ApiErrorCode::TwoFactorRequired,
                    status: Response::HTTP_FORBIDDEN,
                    data: ['two_factor_required' => true],
                );
            }

            if (! $this->twoFactor->verifyChallenge($user, (string) $code)) {
                return ApiResponse::error(
                    message: 'That code did not match. Try again, or use a recovery code.',
                    code: ApiErrorCode::TwoFactorInvalid,
                    status: Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $token = $user->createToken(
            name: 'staff-'.substr(sha1((string) $request->userAgent()), 0, 8),
            abilities: $user->tokenAbilities(),
            // Staff tokens are far shorter-lived than customer tokens: they
            // carry write access to a restaurant.
            expiresAt: now()->addMinutes((int) config('sanctum.staff_token_ttl_minutes')),
        );

        return ApiResponse::success(
            data: [
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
                'user' => (new UserResource($user))->resolve(),
                'restaurants' => $this->accessibleRestaurants($user),
            ],
            message: 'Logged in successfully',
        );
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return ApiResponse::success(
            data: [
                'user' => (new UserResource($user))->resolve(),
                'restaurants' => $this->accessibleRestaurants($user),
            ],
            message: 'Profile fetched successfully',
        );
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success(message: 'Logged out successfully');
    }

    /**
     * The restaurants this account may act on, and in what role. The client
     * uses this to populate its restaurant switcher and to decide which
     * X-Restaurant-Id header to send.
     */
    private function accessibleRestaurants(User $user): array
    {
        if ($user->isSuperAdmin()) {
            return \App\Models\Restaurant::query()
                ->orderBy('name')
                ->get(['id', 'name', 'slug'])
                ->map(fn ($restaurant): array => [
                    'id' => $restaurant->id,
                    'name' => $restaurant->name,
                    'slug' => $restaurant->slug,
                    'role' => 'super_admin',
                ])
                ->all();
        }

        return $user->restaurants()
            ->orderBy('name')
            ->get()
            ->map(fn ($restaurant): array => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'slug' => $restaurant->slug,
                'role' => $restaurant->pivot->role,
            ])
            ->all();
    }
}
