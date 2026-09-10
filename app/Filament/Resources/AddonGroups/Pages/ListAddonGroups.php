<?php

namespace App\Filament\Resources\AddonGroups\Pages;

use App\Filament\Resources\AddonGroups\AddonGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAddonGroups extends ListRecords
{
    protected static string $resource = AddonGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
