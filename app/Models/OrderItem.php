<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An immutable snapshot of what was ordered.
 *
 * product_id is only a convenience link for the reorder feature. The name,
 * price and tax stored here are the record of truth, so editing or deleting a
 * product later can never change what a customer was charged last month.
 *
 * Not tenant-scoped: it is reached through its order, which is.
 */
class OrderItem extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'unit_price' => MoneyCast::class,
            'addons_total' => MoneyCast::class,
            'line_subtotal' => MoneyCast::class,
            'tax_amount' => MoneyCast::class,
            'line_total' => MoneyCast::class,
            'product_snapshot' => 'array',
            'is_veg' => 'boolean',
            'quantity' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function addons(): HasMany
    {
        return $this->hasMany(OrderItemAddon::class);
    }
}
