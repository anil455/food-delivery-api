<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Concerns\BelongsToRestaurant;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use BelongsToRestaurant;
    use HasFactory;
    use SoftDeletes;

    protected $guarded = ['id', 'restaurant_id'];

    protected function casts(): array
    {
        return [
            'base_price' => MoneyCast::class,
            'compare_at_price' => MoneyCast::class,
            'tax_percentage' => 'decimal:2',
            'is_veg' => 'boolean',
            'is_available' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function addonGroups(): BelongsToMany
    {
        return $this->belongsToMany(AddonGroup::class, 'addon_group_product')
            ->withPivot('sort_order')
            ->withTimestamps();
    }

    /** Only products a customer may actually order right now. */
    public function scopeOrderable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_available', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * The price a customer pays for this product, optionally with a variant.
     * A variant replaces the base price outright rather than adjusting it.
     */
    public function priceFor(?ProductVariant $variant = null): Money
    {
        return $variant?->price ?? $this->base_price;
    }

    /** The tax rate to apply, falling back to the restaurant default. */
    public function effectiveTaxPercentage(Restaurant $restaurant): float
    {
        return $this->tax_percentage !== null
            ? (float) $this->tax_percentage
            : (float) $restaurant->tax_percentage;
    }
}
