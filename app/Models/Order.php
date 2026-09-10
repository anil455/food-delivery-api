<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToRestaurant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An order, always belonging to exactly one restaurant.
 *
 * Tenant-scoped, because the restaurant dashboard is the read path that most
 * needs protecting: an order carries the customer name, phone and full delivery
 * address, so a forgotten filter here would be the worst leak in the system.
 *
 * Customer-facing routes read their own history across every store they have
 * ordered from, which is legitimately cross-tenant. Those queries opt out with
 * withoutTenantScope() and pair it with an explicit user_id filter, so the
 * isolation is visible on the same line.
 */
class Order extends Model
{
    use BelongsToRestaurant;
    use HasFactory;
    use SoftDeletes;

    protected $guarded = ['id', 'restaurant_id', 'user_id', 'order_number', 'status'];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'delivery_address' => 'array',

            'subtotal' => MoneyCast::class,
            'discount_total' => MoneyCast::class,
            'delivery_fee' => MoneyCast::class,
            'packaging_fee' => MoneyCast::class,
            'tax_total' => MoneyCast::class,
            'tip' => MoneyCast::class,
            'grand_total' => MoneyCast::class,

            'distance_km' => 'decimal:2',
            'scheduled_for' => 'datetime',
            'placed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', array_map(
            static fn (OrderStatus $status): string => $status->value,
            OrderStatus::active(),
        ));
    }

    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->getKey() : $user);
    }
}
