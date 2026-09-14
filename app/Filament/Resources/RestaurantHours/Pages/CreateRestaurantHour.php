<?php

namespace App\Filament\Resources\RestaurantHours\Pages;

use App\Filament\Resources\RestaurantHours\RestaurantHourResource;
use App\Models\RestaurantHour;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateRestaurantHour extends CreateRecord
{
    protected static string $resource = RestaurantHourResource::class;

    /**
     * There is no restaurant picker on the form — the tenant comes from the
     * panel URL, the same way BelongsToRestaurant would stamp it if this
     * model used that trait.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['restaurant_id'] = Filament::getTenant()?->getKey();

        return $data;
    }

    /**
     * "All week" is not a real day_of_week value — it is a form-only shortcut
     * that fans out into one row per day (0-6), all sharing the same hours.
     */
    protected function handleRecordCreation(array $data): Model
    {
        if ($data['day_of_week'] !== 'all') {
            return parent::handleRecordCreation($data);
        }

        $records = collect(range(0, 6))
            ->map(fn (int $day) => RestaurantHour::create([...$data, 'day_of_week' => $day]));

        return $records->first();
    }
}
