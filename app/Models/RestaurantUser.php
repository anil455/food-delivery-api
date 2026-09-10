<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StaffRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Staff membership of a restaurant.
 *
 * This table is the whole authorisation model for restaurant staff: if there is
 * no active row here for (restaurant, user), the user has no access, whatever
 * the request headers claim.
 */
class RestaurantUser extends Pivot
{
    use HasFactory;

    protected $table = 'restaurant_users';

    public $incrementing = true;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'role' => StaffRole::class,
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
