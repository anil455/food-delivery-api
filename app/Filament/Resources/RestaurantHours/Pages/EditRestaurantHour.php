<?php

namespace App\Filament\Resources\RestaurantHours\Pages;

use App\Filament\Resources\RestaurantHours\RestaurantHourResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRestaurantHour extends EditRecord
{
    protected static string $resource = RestaurantHourResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
