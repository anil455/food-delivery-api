<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer basket. Exactly one per user, and it carries the restaurant.
 *
 * DELIBERATELY NOT tenant-scoped, even though it holds a restaurant_id. A cart
 * is customer-owned, and the whole point of the conflict check is to read a cart
 * belonging to restaurant A while the customer is browsing restaurant B. Under
 * the global scope that read would return nothing and the conflict would go
 * undetected, which is the exact bug the schema exists to prevent.
 *
 * Isolation here is by user: every lookup starts from the authenticated user.
 */
class Cart extends Model
{
    use HasFactory;

    protected $guarded = ['id', 'user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function isEmpty(): bool
    {
        return $this->items()->count() === 0;
    }

    public function belongsToRestaurant(Restaurant|int $restaurant): bool
    {
        $id = $restaurant instanceof Restaurant ? $restaurant->getKey() : $restaurant;

        return (int) $this->restaurant_id === (int) $id;
    }
}
