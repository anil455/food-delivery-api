<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One opening slot on one weekday. Multiple rows per day express split service
 * (lunch, then dinner); closes_at <= opens_at means the slot runs past midnight.
 *
 * DELIBERATELY NOT tenant-scoped, unlike every other table carrying a
 * restaurant_id. Opening hours are public reference data: they are printed on
 * the listing page for every restaurant a customer has not chosen yet, so the
 * nearby search, the public profile endpoint and store resolution would each
 * have to opt out of the scope. An escape hatch used on every read path stops
 * being a signal and starts being noise, which is the one thing that would
 * actually weaken the tenant scope everywhere else.
 *
 * Isolation here comes from the parent instead: reads and writes go through
 * $restaurant->hours(), so the restaurant_id is set by the relation and an
 * admin can only ever reach the hours of a restaurant they already hold.
 */
class RestaurantHour extends Model
{
    use HasFactory;

    protected $guarded = ['id', 'restaurant_id'];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'is_closed' => 'boolean',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function scopeForRestaurant(Builder $query, Restaurant|int $restaurant): Builder
    {
        return $query->where(
            'restaurant_id',
            $restaurant instanceof Restaurant ? $restaurant->getKey() : $restaurant,
        );
    }

    /** True when this slot continues into the next calendar day. */
    public function crossesMidnight(): bool
    {
        return $this->opens_at !== null
            && $this->closes_at !== null
            && $this->closes_at <= $this->opens_at;
    }
}
