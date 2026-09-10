<?php

declare(strict_types=1);

namespace App\Services\Address;

use App\Models\Address;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Address writes, with the single-default rule enforced in one place.
 *
 * MySQL has no partial unique index, so "exactly one default per user" cannot be
 * a database constraint. Every write that could affect it therefore goes through
 * here, inside a transaction.
 */
final class AddressService
{
    public function create(User $user, array $attributes): Address
    {
        return DB::transaction(function () use ($user, $attributes): Address {
            $isFirst = $user->addresses()->count() === 0;

            // The first address a customer saves is their default whether they
            // asked for it or not, otherwise checkout has nothing to preselect.
            $shouldBeDefault = ($attributes['is_default'] ?? false) || $isFirst;

            if ($shouldBeDefault) {
                $this->clearDefault($user);
            }

            $address = $user->addresses()->create([
                ...$attributes,
                'is_default' => $shouldBeDefault,
            ]);

            return $address->refresh();
        });
    }

    public function update(Address $address, array $attributes): Address
    {
        return DB::transaction(function () use ($address, $attributes): Address {
            if (($attributes['is_default'] ?? false) === true) {
                $this->clearDefault($address->user);
            }

            $address->fill($attributes)->save();

            return $address->refresh();
        });
    }

    public function makeDefault(Address $address): Address
    {
        return DB::transaction(function () use ($address): Address {
            $this->clearDefault($address->user);

            $address->forceFill(['is_default' => true])->save();

            return $address->refresh();
        });
    }

    public function delete(Address $address): void
    {
        DB::transaction(function () use ($address): void {
            $wasDefault = $address->is_default;
            $user = $address->user;

            $address->delete();

            // Never leave a customer with addresses but no default.
            if ($wasDefault) {
                $next = $user->addresses()->orderBy('id')->first();

                $next?->forceFill(['is_default' => true])->save();
            }
        });
    }

    private function clearDefault(User $user): void
    {
        $user->addresses()->where('is_default', true)->update(['is_default' => false]);
    }
}
