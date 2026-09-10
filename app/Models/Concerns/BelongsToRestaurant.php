<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Restaurant;
use App\Models\Scopes\RestaurantScope;
use App\Services\Tenancy\RestaurantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as tenant-owned.
 *
 * Adds the fail-closed global scope, stamps restaurant_id on create, and exposes
 * the one sanctioned escape hatch. Any model with a restaurant_id column should
 * use this trait — if it does not, it has no tenant protection at all.
 */
trait BelongsToRestaurant
{
    public static function bootBelongsToRestaurant(): void
    {
        static::addGlobalScope(new RestaurantScope);

        static::creating(function (self $model): void {
            if ($model->getAttribute('restaurant_id') !== null) {
                return;
            }

            $context = app(RestaurantContext::class);

            if ($context->has()) {
                $model->setAttribute('restaurant_id', $context->id());
            }

            // If no context is set, restaurant_id stays null and the NOT NULL
            // constraint rejects the insert — loudly, which is the point.
        });
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /**
     * The only sanctioned way to read across tenants.
     *
     * Grep for this to audit every cross-tenant read in the codebase.
     */
    public static function withoutTenantScope(): Builder
    {
        return static::query()->withoutGlobalScope(RestaurantScope::class);
    }

    /** Explicitly scope to a restaurant other than the ambient context. */
    public function scopeForRestaurant(Builder $query, Restaurant|int $restaurant): Builder
    {
        $id = $restaurant instanceof Restaurant ? $restaurant->getKey() : $restaurant;

        return $query->withoutGlobalScope(RestaurantScope::class)
            ->where($this->qualifyColumn('restaurant_id'), $id);
    }
}
