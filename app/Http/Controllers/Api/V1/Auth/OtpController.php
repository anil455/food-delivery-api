<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\ApiErrorCode;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Auth\SendOtpRequest;
use App\Http\Requests\V1\Auth\VerifyOtpRequest;
use App\Http\Resources\V1\UserResource;
use App\Models\User;
use App\Services\Otp\OtpService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class OtpController extends Controller
{
    public function __construct(private readonly OtpService $otp) {}

    /**
     * Issue a code.
     *
     * The response is identical in shape and timing whether or not the number
     * belongs to an existing user: account existence is revealed only after a
     * successful verification.
     */
    public function send(SendOtpRequest $request): JsonResponse
    {
        $result = $this->otp->send(
            phone: $request->phone(),
            purpose: 'login',
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return ApiResponse::success(
            data: $result->toArray(),
            message: 'Verification code sent successfully',
        );
    }

    /** Resend is the same operation; the cooldown in OtpRateLimiter governs both. */
    public function resend(SendOtpRequest $request): JsonResponse
    {
        return $this->send($request);
    }

    /** Check a code and log the customer in, creating the account on first use. */
    public function verify(VerifyOtpRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $verification = $this->otp->verify(
            verificationId: $validated['verification_id'],
            phone: $validated['phone'],
            code: $validated['otp'],
            ip: $request->ip(),
        );

        $existing = User::query()->where('phone', $verification->phone)->first();

        // Refuse a blocked account before issuing anything, and before touching
        // last_login_at, so a disabled user leaves no trace of a successful login.
        if ($existing !== null && $existing->status !== 'active') {
            return ApiResponse::error(
                message: 'This account has been disabled. Please contact support.',
                code: ApiErrorCode::AccountDisabled,
                status: Response::HTTP_FORBIDDEN,
            );
        }

        $user = DB::transaction(function () use ($verification, $existing): User {
            $user = $existing ?? new User(['phone' => $verification->phone]);

            // type, status and phone_verified_at are guarded against mass
            // assignment, so they are set explicitly here.
            $user->forceFill([
                'phone' => $verification->phone,
                'type' => UserType::Customer,
                'status' => $user->exists ? $user->status : 'active',
                'phone_verified_at' => $user->phone_verified_at ?? now(),
                'last_login_at' => now(),
            ])->save();

            // A newly inserted model holds only the columns that were written;
            // reload so the resource sees every column the table has.
            return $user->refresh();
        });

        $token = $user->createToken(
            name: 'customer-'.substr(sha1((string) $request->userAgent()), 0, 8),
            abilities: $user->tokenAbilities(),
            expiresAt: now()->addMinutes((int) config('sanctum.customer_token_ttl_minutes')),
        );

        return ApiResponse::success(
            data: [
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
                'user' => new UserResource($user),
            ],
            message: 'Logged in successfully',
        );
    }
}
