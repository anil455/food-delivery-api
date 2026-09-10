<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\CouponType;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A discount code, owned either by one restaurant or by the platform when
 * restaurant_id is null.
 *
 * Not tenant-scoped precisely because of that null case: a platform-wide coupon
 * has no tenant, and the global scope would make it invisible to everyone.
 * Eligibility is expressed by the usableAt scope instead.
 */
class Coupon extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $guarded = ['id', 'times_used'];

    protected function casts(): array
    {
        return [
            'type' => CouponType::class,
            'max_discount' => MoneyCast::class,
            'min_order_amount' => MoneyCast::class,
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    /** Coupons usable at a restaurant: its own, plus platform-wide ones. */
    public function scopeUsableAt(Builder $query, Restaurant|int $restaurant): Builder
    {
        $id = $restaurant instanceof Restaurant ? $restaurant->getKey() : $restaurant;

        return $query->where(fn (Builder $inner) => $inner
            ->where('restaurant_id', $id)
            ->orWhereNull('restaurant_id'));
    }

    /** Active, started, and not yet finished. */
    public function scopeLive(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(fn (Builder $inner) => $inner->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $inner) => $inner->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    /**
     * The discount this coupon gives against a subtotal.
     *
     * Percentage coupons hold a whole-percent rate in `value`. The result is
     * capped at max_discount and can never exceed the subtotal itself, so a
     * generous coupon cannot produce a negative order total.
     */
    public function discountFor(Money $subtotal): Money
    {
        $discount = $this->type === CouponType::Percent
            ? $subtotal->percentage((float) $this->value)
            : Money::fromMinor((int) $this->value);

        if ($this->max_discount !== null) {
            $discount = $discount->min($this->max_discount);
        }

        return $discount->min($subtotal);
    }

    /** Active, inside its window, and not fully redeemed. */
    public function isLive(): bool
    {
        if (! $this->is_active || $this->hasReachedGlobalLimit()) {
            return false;
        }

        if ($this->starts_at !== null && $this->starts_at->isFuture()) {
            return false;
        }

        return $this->ends_at === null || $this->ends_at->isFuture();
    }

    public function hasReachedGlobalLimit(): bool
    {
        return $this->usage_limit !== null && $this->times_used >= $this->usage_limit;
    }

    public function hasReachedUserLimit(User|int $user): bool
    {
        if ($this->per_user_limit === null) {
            return false;
        }

        $userId = $user instanceof User ? $user->getKey() : $user;

        return $this->redemptions()->where('user_id', $userId)->count() >= $this->per_user_limit;
    }
}
