<?php

namespace App\Filament\Resources\Banners\Pages;

use App\Filament\Resources\Banners\BannerResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateBanner extends CreateRecord
{
    protected static string $resource = BannerResource::class;

    /**
     * There is no restaurant picker on the form — the tenant comes from the
     * panel URL, the same way BelongsToRestaurant would stamp it if Banner
     * used that trait.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['restaurant_id'] = Filament::getTenant()?->getKey();

        return $data;
    }
}
