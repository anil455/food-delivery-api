<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A line in a cart. Not tenant-scoped, for the same reason as Cart: it is
 * reached only through its parent cart, which is reached only through its user.
 */
class CartItem extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            // Kept only so the cart can flag a price change since the item was
            // added. Checkout ignores it and recomputes from the products table.
            'price_at_add' => MoneyCast::class,
            'quantity' => 'integer',
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function addons(): HasMany
    {
        return $this->hasMany(CartItemAddon::class);
    }
}
