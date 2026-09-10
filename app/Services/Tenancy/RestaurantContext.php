<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Restaurant;
use Closure;
use RuntimeException;

/**
 * The resolved tenant for the current request.
 *
 * Registered as a singleton, so it lives exactly as long as one request (or one
 * queued job). Middleware sets it after validating that the authenticated user
 * is actually entitled to that restaurant — nothing else may set it.
 */
final class RestaurantContext
{
    private ?int $restaurantId = null;

    private ?Restaurant $restaurant = null;

    /**
     * When true, RestaurantScope stands down. Only reachable through
     * runCrossTenant(), which is deliberately conspicuous in a diff.
     */
    private bool $crossTenant = false;

    public function set(Restaurant|int $restaurant): void
    {
        if ($restaurant instanceof Restaurant) {
            $this->restaurant = $restaurant;
            $this->restaurantId = $restaurant->getKey();

            return;
        }

        $this->restaurant = null;
        $this->restaurantId = $restaurant;
    }

    public function id(): ?int
    {
        return $this->restaurantId;
    }

    public function requireId(): int
    {
        if ($this->restaurantId === null) {
            throw new RuntimeException(
                'A restaurant context is required here but none is set. '
                .'Check that the tenant-resolution middleware runs on this route.'
            );
        }

        return $this->restaurantId;
    }

    public function has(): bool
    {
        return $this->restaurantId !== null;
    }

    /** Lazily hydrates the model if only the id was set. */
    public function restaurant(): ?Restaurant
    {
        if ($this->restaurant === null && $this->restaurantId !== null) {
            $this->restaurant = Restaurant::query()->find($this->restaurantId);
        }

        return $this->restaurant;
    }

    public function forget(): void
    {
        $this->restaurantId = null;
        $this->restaurant = null;
    }

    public function isCrossTenant(): bool
    {
        return $this->crossTenant;
    }

    /**
     * Turn on cross-tenant reads for the rest of this request.
     *
     * For the platform panel, whose whole purpose is to look across every
     * restaurant. Unlike runCrossTenant() there is no callback to fall out of,
     * so this is deliberately only reachable from middleware on that one panel.
     */
    public function enableCrossTenant(): void
    {
        $this->crossTenant = true;
    }

    /**
     * Run a callback with the tenant temporarily set to a specific restaurant.
     *
     * For work whose tenant is decided by the data rather than by the request:
     * a cart and its items belong to the cart restaurant, which is not always
     * the store the customer happens to be browsing. Restores the previous
     * context even if the callback throws.
     */
    public function runForRestaurant(Restaurant|int $restaurant, Closure $callback): mixed
    {
        $previousId = $this->restaurantId;
        $previousModel = $this->restaurant;
        $previousCrossTenant = $this->crossTenant;

        $this->set($restaurant);
        $this->crossTenant = false;

        try {
            return $callback();
        } finally {
            $this->restaurantId = $previousId;
            $this->restaurant = $previousModel;
            $this->crossTenant = $previousCrossTenant;
        }
    }

    /**
     * Run a callback with tenant scoping suspended.
     *
     * For super-admin reads, console commands, seeders and the nearby-restaurant
     * search — which is cross-tenant by definition. Restores the previous state
     * even if the callback throws.
     */
    public function runCrossTenant(Closure $callback): mixed
    {
        $previous = $this->crossTenant;
        $this->crossTenant = true;

        try {
            return $callback();
        } finally {
            $this->crossTenant = $previous;
        }
    }
}
