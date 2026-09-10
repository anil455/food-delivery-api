<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Restaurants\Pages;

use App\Enums\StaffRole;
use App\Enums\UserType;
use App\Filament\Platform\Resources\Restaurants\RestaurantResource;
use App\Models\Restaurant;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateRestaurant extends CreateRecord
{
    protected static string $resource = RestaurantResource::class;

    /**
     * Create the restaurant and its first owner together.
     *
     * One transaction, because a restaurant with no owner is unmanageable and
     * a half-finished onboarding is worse than none. Mirrors exactly what
     * POST /api/v1/super-admin/restaurants does.
     */
    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): Restaurant {
            $restaurant = new Restaurant;

            // status and commission_rate are guarded on the model, so they are
            // set explicitly here rather than mass-assigned.
            $restaurant->forceFill([
                ...collect($data)->except(['owner_name', 'owner_email', 'owner_password'])->all(),
                'slug' => $this->uniqueSlug($data['name']),
                'avg_prep_time_minutes' => config('delivery.default_prep_time_minutes'),
                'tax_percentage' => config('delivery.default_tax_percentage'),
            ])->save();

            $owner = new User;
            $owner->forceFill([
                'name' => $data['owner_name'],
                'email' => $data['owner_email'],
                'password' => $data['owner_password'],
                'email_verified_at' => now(),
                'type' => UserType::Staff,
                'status' => 'active',
                'is_super_admin' => false,
            ])->save();

            $restaurant->staff()->attach($owner->getKey(), [
                'role' => StaffRole::Owner->value,
                'status' => 'active',
            ]);

            return $restaurant;
        });
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 2;

        while (Restaurant::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
