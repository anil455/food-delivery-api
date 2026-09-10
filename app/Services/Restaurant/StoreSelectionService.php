<?php

declare(strict_types=1);

namespace App\Services\Restaurant;

use App\Models\Restaurant;
use App\Models\User;
use App\Services\Geo\GeoPoint;
use App\Services\Geo\NearbyRestaurantFinder;
use App\Services\Tenancy\RestaurantContext;

/**
 * Decides which store a customer is shopping in.
 *
 * Order of preference:
 *   1. a store the customer chose explicitly, if it is still active
 *   2. the nearest store that delivers to their default address
 *   3. nothing, and the client is told to ask
 *
 * Step 2 is what makes the app useful on first launch, and step 1 is what stops
 * it silently moving the customer somewhere else afterwards.
 */
final class StoreSelectionService
{
    public function __construct(
        private readonly NearbyRestaurantFinder $finder,
        private readonly RestaurantContext $context,
    ) {}

    public function resolveFor(User $user, ?GeoPoint $origin = null): ?Restaurant
    {
        $chosen = $this->chosenStore($user);

        if ($chosen !== null) {
            return $chosen;
        }

        return $this->nearestFor($user, $origin);
    }

    /** The store the customer picked, if it is still eligible. */
    public function chosenStore(User $user): ?Restaurant
    {
        if ($user->selected_restaurant_id === null) {
            return null;
        }

        $restaurant = $this->context->runCrossTenant(
            fn () => Restaurant::query()
                ->with(['hours', 'holidays'])
                ->find($user->selected_restaurant_id)
        );

        // A store that has since been deactivated must not keep serving a menu.
        if ($restaurant === null || ! $restaurant->isActive()) {
            return null;
        }

        return $restaurant;
    }

    /**
     * Nearest store that will actually deliver to the customer, using the given
     * coordinates or their default address.
     */
    public function nearestFor(User $user, ?GeoPoint $origin = null): ?Restaurant
    {
        $origin ??= $this->defaultAddressPoint($user);

        if ($origin === null) {
            return null;
        }

        return $this->finder
            ->find(origin: $origin, onlyDeliverable: true, limit: 1)
            ->first();
    }

    public function select(User $user, Restaurant $restaurant): void
    {
        $user->forceFill(['selected_restaurant_id' => $restaurant->getKey()])->save();
    }

    public function clear(User $user): void
    {
        $user->forceFill(['selected_restaurant_id' => null])->save();
    }

    private function defaultAddressPoint(User $user): ?GeoPoint
    {
        $address = $user->addresses()
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        if ($address === null) {
            return null;
        }

        return new GeoPoint((float) $address->latitude, (float) $address->longitude);
    }
}
