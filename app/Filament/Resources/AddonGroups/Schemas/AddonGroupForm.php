<?php

declare(strict_types=1);

namespace App\Filament\Resources\AddonGroups\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class AddonGroupForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->helperText('E.g. "Choice of drink", "Extra toppings".')
                    ->columnSpanFull(),

                Textarea::make('description')
                    ->rows(2)
                    ->maxLength(1000)
                    ->columnSpanFull(),

                Toggle::make('is_required')
                    ->helperText('Customer must choose from this group before adding the item to cart.'),

                TextInput::make('exclusive_key')
                    ->label('Shared choice key')
                    ->maxLength(64)
                    ->regex('/^[a-z0-9_-]+$/')
                    ->placeholder('e.g. beverage')
                    ->helperText('Leave EMPTY to let customers pick as many add-ons as they like (Extras, Toppings). Fill it to make this a "choose one" group. Groups with the SAME key share one choice across all of them (e.g. "beverage" on both "Choose Beverage" and "Choose Beverage Upgrade"). A stand-alone choose-one group (Size, Side, Dessert) gets its own unique key, e.g. "size". Lowercase letters, numbers, "-" or "_".')
                    ->columnSpanFull(),

                Toggle::make('is_active')
                    ->default(true)
                    ->helperText('Switch off to hide this group from every product it is attached to.'),

                TextInput::make('sort_order')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->maxValue(9999)
                    ->helperText('Lower numbers appear first on the product page.'),
            ]);
    }
}
