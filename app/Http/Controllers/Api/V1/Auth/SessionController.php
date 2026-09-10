<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\UserResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SessionController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(
            data: new UserResource($request->user()),
            message: 'Profile fetched successfully',
        );
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => [
                'sometimes', 'nullable', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($request->user()->getKey()),
            ],
        ]);

        $user = $request->user();
        $user->fill($validated)->save();

        return ApiResponse::success(
            data: new UserResource($user),
            message: 'Profile updated successfully',
        );
    }

    /** Revokes only the token that made this request. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success(message: 'Logged out successfully');
    }

    /** Revokes every token for this user, for a lost or stolen device. */
    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return ApiResponse::success(message: 'Logged out of all devices successfully');
    }

    /**
     * Account deletion, for DPDP and GDPR erasure requests.
     *
     * The user row is soft-deleted and the directly identifying columns are
     * scrubbed immediately. Orders are deliberately left standing: they are
     * financial records the restaurant is required to keep, and they already
     * carry their own snapshot of the delivery address rather than a live join.
     * What they lose is the link back to a named, contactable person.
     */
    public function destroyAccount(Request $request): JsonResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($user): void {
            $user->tokens()->delete();
            $user->addresses()->delete();
            $user->cart?->items()->delete();
            $user->cart?->delete();

            // Free the unique phone and email so the person can sign up again,
            // and leave nothing personally identifying on the row.
            $user->forceFill([
                'name' => null,
                'phone' => null,
                'email' => null,
                'phone_verified_at' => null,
                'email_verified_at' => null,
                'avatar_path' => null,
                'selected_restaurant_id' => null,
                'status' => 'deleted',
            ])->save();

            $user->delete();
        });

        return ApiResponse::success(message: 'Your account has been deleted.');
    }
}
