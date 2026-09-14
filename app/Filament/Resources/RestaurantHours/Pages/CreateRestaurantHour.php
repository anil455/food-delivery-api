<?php

namespace App\Filament\Resources\RestaurantHours\Pages;

use App\Filament\Resources\RestaurantHours\RestaurantHourResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

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
}
