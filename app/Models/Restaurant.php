<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\RestaurantStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The tenant root.
 *
 * Deliberately does NOT use BelongsToRestaurant: it is the thing being scoped
 * to, not a thing that is scoped. Access control for restaurants themselves
 * lives in RestaurantPolicy.
 */
class Restaurant extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $guarded = [
        'id',
        'status',
        'commission_rate',
    ];

    protected function casts(): array
    {
        return [
            'status' => RestaurantStatus::class,
            'is_accepting_orders' => 'boolean',

            // Kept as strings, not floats: these feed a Haversine expression
            // where a lost decimal place is roughly a kilometre of error.
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'delivery_radius_km' => 'decimal:2',
            'tax_percentage' => 'decimal:2',
            'commission_rate' => 'decimal:2',

            'min_order_amount' => MoneyCast::class,
            'delivery_fee_base' => MoneyCast::class,
            'delivery_fee_per_km' => MoneyCast::class,
            'packaging_fee' => MoneyCast::class,
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    // ── Relationships ───────────────────────────────────────────────────────

    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'restaurant_users')
            ->using(RestaurantUser::class)
            ->withPivot(['role', 'status'])
            ->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(RestaurantUser::class);
    }

    public function hours(): HasMany
    {
        return $this->hasMany(RestaurantHour::class);
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(RestaurantHoliday::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function productVariants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function addonGroups(): HasMany
    {
        return $this->hasMany(AddonGroup::class);
    }

    public function addons(): HasMany
    {
        return $this->hasMany(Addon::class);
    }

    public function coupons(): HasMany
    {
        return $this->hasMany(Coupon::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    // ── Scopes ──────────────────────────────────────────────────────────────

    /** Only active restaurants are discoverable by customers. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', RestaurantStatus::Active->value);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === RestaurantStatus::Active;
    }

    /**
     * Whether this restaurant delivers to a point, ignoring opening hours.
     * Openness is a separate question answered by OpeningHoursService.
     */
    public function deliversWithin(float $distanceKm): bool
    {
        return $distanceKm <= (float) $this->delivery_radius_km;
    }
}
