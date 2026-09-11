<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Schemas;

use App\Models\Category;
use App\Services\Tenancy\RestaurantContext;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Item')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (string $operation, $state, callable $set) => $operation === 'create'
                            ? $set('slug', Str::slug((string) $state))
                            : null),

                    /*
                     * Category::query() is already narrowed to this restaurant
                     * by the global scope, so the dropdown can only ever offer
                     * categories that belong here. The composite foreign key in
                     * the database would reject anything else regardless.
                     */
                    Select::make('category_id')
                        ->label('Category')
                        ->options(fn (): array => Category::query()
                            ->orderBy('sort_order')
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->required()
                        ->helperText('Only this restaurant categories are listed.'),

                    TextInput::make('slug')->required()->maxLength(255),

                    Textarea::make('description')
                        ->rows(3)
                        ->maxLength(2000)
                        ->columnSpanFull(),

                    FileUpload::make('image_path')
                        ->label('Photo')
                        ->image()
                        ->disk(config('filesystems.default'))
                        ->directory('products')
                        ->maxSize(4096)
                        ->imageEditor()
                        ->columnSpanFull(),
                ]),

            Section::make('Price')
                ->columns(2)
                ->description('Entered in rupees, stored as paise. No float ever touches a price.')
                ->schema([
                    self::money('base_price', 'Price')->required(),
                    self::money('compare_at_price', 'Was (strike-through)')
                        ->helperText('Optional. Shown crossed out next to the price.'),
                    TextInput::make('tax_percentage')
                        ->label('Tax % (override)')
                        ->numeric()->minValue(0)->maxValue(100)->suffix('%')
                        ->helperText('Leave blank to use the restaurant default.'),
                ]),

            Section::make('Add-ons')
                ->description('Attach the choice groups a customer sees on this item, e.g. "Choice of drink" or "Extra toppings". Create groups and their options under Menu → Add-on Groups first.')
                ->schema([
                    Select::make('addonGroups')
                        ->label('Add-on groups')
                        ->relationship('addonGroups', 'name')
                        ->multiple()
                        ->preload()
                        ->searchable()
                        // The pivot table is tenant-scoped like everything else,
                        // but Eloquent's sync() only writes the two foreign keys
                        // by default — restaurant_id has to be supplied explicitly.
                        ->pivotData(fn (): array => ['restaurant_id' => app(RestaurantContext::class)->requireId()])
                        ->helperText('Only active groups belonging to this restaurant are listed.'),
                ]),

            Section::make('Details')
                ->columns(3)
                ->schema([
                    Toggle::make('is_veg')->label('Vegetarian')->default(true),
                    Select::make('spice_level')
                        ->options([0 => 'None', 1 => 'Mild', 2 => 'Medium', 3 => 'Hot', 4 => 'Very hot', 5 => 'Extreme'])
                        ->native(false),
                    TextInput::make('prep_time_minutes')
                        ->label('Prep time')->numeric()->minValue(1)->maxValue(240)->suffix('min'),
                    TextInput::make('sort_order')->numeric()->default(0)->minValue(0)->maxValue(9999),
                    Toggle::make('is_available')
                        ->label('In stock')->default(true)
                        ->helperText('Switch off when it sells out. Comes back with one click.'),
                    Toggle::make('is_active')
                        ->label('On the menu')->default(true)
                        ->helperText('Switch off to retire the item without deleting it.'),
                ]),
        ]);
    }

    private static function money(string $field, string $label): TextInput
    {
        return TextInput::make($field)
            ->label($label)
            ->prefix('₹')
            ->minValue(0)
            ->formatStateUsing(fn ($state): ?string => $state === null
                ? null
                : number_format((int) (is_object($state) ? $state->minor : $state) / 100, 2, '.', ''))
            ->dehydrateStateUsing(fn ($state) => $state === null || $state === ''
                ? null
                : (int) round(((float) $state) * 100));
    }
}
