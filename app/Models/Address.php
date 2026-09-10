<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A customer saved address. Owned by a user, not by a restaurant, so it is not
 * tenant-scoped: isolation here is per-user and enforced by AddressPolicy.
 */
class Address extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $guarded = ['id', 'user_id'];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'is_default' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
