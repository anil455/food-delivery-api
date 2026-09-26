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

    /**
     * The limits are derived, never typed in. A group with a shared choice key
     * is a "choose one" group (max 1); without a key the customer may pick any
     * number (max 0 = no limit, which the cart already treats as unlimited).
     * A required group needs at least one pick.
     */
    protected static function booted(): void
    {
        static::saving(function (self $group): void {
            $group->max_select = filled($group->exclusive_key) ? 1 : 0;
            $group->min_select = $group->is_required ? 1 : 0;
        });
    }

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
