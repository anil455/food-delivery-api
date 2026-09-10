<?php

namespace App\Filament\Resources\AddonGroups\Pages;

use App\Filament\Resources\AddonGroups\AddonGroupResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditAddonGroup extends EditRecord
{
    protected static string $resource = AddonGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
