<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Customer\StoreAddressRequest;
use App\Http\Requests\V1\Customer\UpdateAddressRequest;
use App\Http\Resources\V1\AddressResource;
use App\Models\Address;
use App\Services\Address\AddressService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Addresses belong to a user, not a restaurant, so isolation here is per-user.
 *
 * Every lookup starts from the authenticated user relation rather than from
 * Address::find(), which means another customer id simply produces a 404 and
 * there is no ownership check to forget.
 */
class AddressController extends Controller
{
    public function __construct(private readonly AddressService $addresses) {}

    public function index(Request $request): JsonResponse
    {
        $addresses = $request->user()->addresses()
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get();

        return ApiResponse::success(
            data: AddressResource::collection($addresses)->resolve(),
            message: 'Addresses fetched successfully',
        );
    }

    public function store(StoreAddressRequest $request): JsonResponse
    {
        $address = $this->addresses->create($request->user(), $request->validated());

        return ApiResponse::created(new AddressResource($address), 'Address saved successfully');
    }

    public function show(Request $request, int $address): JsonResponse
    {
        return ApiResponse::success(
            data: new AddressResource($this->ownedOrFail($request, $address)),
            message: 'Address fetched successfully',
        );
    }

    public function update(UpdateAddressRequest $request, int $address): JsonResponse
    {
        $updated = $this->addresses->update(
            $this->ownedOrFail($request, $address),
            $request->validated(),
        );

        return ApiResponse::success(new AddressResource($updated), 'Address updated successfully');
    }

    public function destroy(Request $request, int $address): JsonResponse
    {
        $this->addresses->delete($this->ownedOrFail($request, $address));

        return ApiResponse::noContent('Address deleted successfully');
    }

    public function makeDefault(Request $request, int $address): JsonResponse
    {
        $updated = $this->addresses->makeDefault($this->ownedOrFail($request, $address));

        return ApiResponse::success(new AddressResource($updated), 'Default address updated successfully');
    }

    private function ownedOrFail(Request $request, int $addressId): Address
    {
        return $request->user()->addresses()->findOrFail($addressId);
    }
}
