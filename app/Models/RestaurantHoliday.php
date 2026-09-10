<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-off closure that overrides the weekly schedule entirely.
 *
 * Not tenant-scoped, for the same reason as RestaurantHour: it is public
 * reference data reached only through its parent restaurant.
 */
class RestaurantHoliday extends Model
{
    use HasFactory;

    protected $guarded = ['id', 'restaurant_id'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
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
}
