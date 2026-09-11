<?php

declare(strict_types=1);

namespace App\Filament\Resources\Categories\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                /*
                 * No restaurant picker. The tenant is resolved from the URL and
                 * stamped by BelongsToRestaurant, so a category cannot be filed
                 * under someone else's restaurant even by accident.
                 */
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (string $operation, $state, callable $set) => $operation === 'create'
                        ? $set('slug', Str::slug((string) $state))
                        : null),

                TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Appears in public URLs. Unique within this restaurant.'),

                Textarea::make('description')
                    ->rows(2)
                    ->maxLength(1000)
                    ->columnSpanFull(),

                FileUpload::make('image_path')
                    ->label('Image')
                    ->image()
                    ->disk(config('filesystems.default'))
                    ->directory('categories')
                    ->maxSize(4096)
                    ->imageEditor()
                    ->columnSpanFull(),

                TextInput::make('sort_order')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->maxValue(9999)
                    ->helperText('Lower numbers appear first on the menu.'),

                Toggle::make('is_active')
                    ->default(true)
                    ->helperText('Switch off to hide the whole category from customers.'),
            ]);
    }
}
