<?php

namespace App\Filament\Resources\RestaurantHours\Pages;

use App\Filament\Resources\RestaurantHours\RestaurantHourResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRestaurantHours extends ListRecords
{
    protected static string $resource = RestaurantHourResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
