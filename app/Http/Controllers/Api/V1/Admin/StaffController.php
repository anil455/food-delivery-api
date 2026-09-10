<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\StaffRole;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantUser;
use App\Models\User;
use App\Services\Tenancy\RestaurantContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Staff membership for the restaurant in context.
 *
 * The membership row is the whole authorisation model, so this controller is
 * the most privileged one a restaurant has. Only owners and managers reach it,
 * and nobody can grant a role above their own.
 */
class StaffController extends Controller
{
    public function __construct(private readonly RestaurantContext $context) {}

    public function index(): JsonResponse
    {
        $restaurant = $this->current();

        $this->authorize('manageStaff', $restaurant);

        $staff = $restaurant->memberships()
            ->with('user')
            ->get()
            ->map(fn (RestaurantUser $membership): array => $this->present($membership))
            ->all();

        return ApiResponse::success($staff, 'Staff fetched successfully');
    }

    public function store(Request $request): JsonResponse
    {
        $restaurant = $this->current();

        $this->authorize('manageStaff', $restaurant);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'role' => ['required', Rule::in($this->assignableRoles($request->user(), $restaurant))],
        ]);

        $membership = DB::transaction(function () use ($restaurant, $validated): RestaurantUser {
            $user = new User;

            // type and status are guarded: a request can never mint a customer
            // into staff or activate a disabled account through this endpoint.
            $user->forceFill([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'email_verified_at' => now(),
                'type' => UserType::Staff,
                'status' => 'active',
                'is_super_admin' => false,
            ])->save();

            $restaurant->staff()->attach($user->getKey(), [
                'role' => $validated['role'],
                'status' => 'active',
            ]);

            return $restaurant->memberships()->with('user')->where('user_id', $user->getKey())->sole();
        });

        return ApiResponse::created($this->present($membership), 'Staff member added successfully');
    }

    public function update(Request $request, int $staff): JsonResponse
    {
        $restaurant = $this->current();

        $this->authorize('manageStaff', $restaurant);

        $membership = $restaurant->memberships()->with('user')->where('user_id', $staff)->firstOrFail();

        $this->assertNotSelf($request, $membership, 'You cannot change your own role.');

        $validated = $request->validate([
            'role' => ['sometimes', Rule::in($this->assignableRoles($request->user(), $restaurant))],
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
        ]);

        $membership->forceFill($validated)->save();

        return ApiResponse::success($this->present($membership->fresh('user')), 'Staff member updated successfully');
    }

    public function destroy(Request $request, int $staff): JsonResponse
    {
        $restaurant = $this->current();

        $this->authorize('manageStaff', $restaurant);

        $membership = $restaurant->memberships()->with('user')->where('user_id', $staff)->firstOrFail();

        $this->assertNotSelf($request, $membership, 'You cannot remove yourself from this restaurant.');

        // Removing the last owner would leave the restaurant unmanageable.
        if ($membership->role === StaffRole::Owner
            && $restaurant->memberships()->where('role', StaffRole::Owner->value)->count() <= 1) {
            return ApiResponse::error(
                message: 'A restaurant must keep at least one owner.',
                code: \App\Enums\ApiErrorCode::Conflict,
                status: \Illuminate\Http\Response::HTTP_CONFLICT,
            );
        }

        DB::transaction(function () use ($restaurant, $membership): void {
            // Revoke live sessions immediately: access ends when the row does.
            $membership->user?->tokens()->delete();

            $restaurant->staff()->detach($membership->user_id);
        });

        return ApiResponse::noContent('Staff member removed successfully');
    }

    /**
     * Nobody may grant a role above their own, so a manager cannot promote
     * themselves by creating an owner and logging in as them.
     *
     * @return array<int, string>
     */
    private function assignableRoles(User $actor, Restaurant $restaurant): array
    {
        if ($actor->isSuperAdmin()) {
            return array_map(fn (StaffRole $role): string => $role->value, StaffRole::cases());
        }

        $actorRole = $actor->roleInRestaurant($restaurant);

        if ($actorRole === null) {
            return [];
        }

        return collect(StaffRole::cases())
            ->filter(fn (StaffRole $role): bool => $actorRole->rank() >= $role->rank())
            ->map(fn (StaffRole $role): string => $role->value)
            ->values()
            ->all();
    }

    private function assertNotSelf(Request $request, RestaurantUser $membership, string $message): void
    {
        abort_if(
            (int) $membership->user_id === (int) $request->user()->getKey(),
            \Illuminate\Http\Response::HTTP_CONFLICT,
            $message,
        );
    }

    private function present(RestaurantUser $membership): array
    {
        return [
            'user_id' => $membership->user_id,
            'name' => $membership->user?->name,
            'email' => $membership->user?->email,
            'role' => $membership->role->value,
            'status' => $membership->status,
            'last_login_at' => $membership->user?->last_login_at?->toIso8601String(),
        ];
    }

    private function current(): Restaurant
    {
        return $this->context->restaurant();
    }
}
