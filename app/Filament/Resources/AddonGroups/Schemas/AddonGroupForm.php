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

                TextInput::make('min_select')
                    ->label('Minimum selections')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->maxValue(255)
                    ->required(),

                TextInput::make('max_select')
                    ->label('Maximum selections')
                    ->numeric()
                    ->default(1)
                    ->minValue(1)
                    ->maxValue(255)
                    ->required(),

                Toggle::make('is_required')
                    ->helperText('Customer must choose from this group before adding the item to cart.'),

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
