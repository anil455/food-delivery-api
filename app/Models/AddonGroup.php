<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A reusable set of choices, such as "Choice of drink". Groups attach to many
 * products, because real kitchens reuse the same choices across a menu.
 */
class AddonGroup extends Model
{
    use BelongsToRestaurant;
    use HasFactory;
    use SoftDeletes;

    protected $guarded = ['id', 'restaurant_id'];

    protected function casts(): array
    {
        return [
            'min_select' => 'integer',
            'max_select' => 'integer',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function addons(): HasMany
    {
        return $this->hasMany(Addon::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'addon_group_product')
            ->withPivot('sort_order')
            ->withTimestamps();
    }
}
