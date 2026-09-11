<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Restaurants\Pages;

use App\Filament\Platform\Resources\Restaurants\RestaurantResource;
use Filament\Resources\Pages\EditRecord;

class EditRestaurant extends EditRecord
{
    protected static string $resource = RestaurantResource::class;

    /**
     * Drops the Money-cast fields (min_order_amount, delivery_fee_base, ...)
     * before the record's attributes become Livewire component state.
     *
     * None of them appear in the form schema — this platform panel does not
     * edit pricing — but Filament fills the component's state from every
     * model attribute regardless of what the schema renders. Livewire has no
     * synth for the Money DTO those casts return, so leaving them in state
     * crashes the page with "Property type not supported in Livewire" before
     * a single field is drawn.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return collect($data)
            ->except(['min_order_amount', 'delivery_fee_base', 'delivery_fee_per_km', 'packaging_fee'])
            ->all();
    }
}
