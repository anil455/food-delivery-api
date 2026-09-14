<?php

namespace App\Filament\Resources\RestaurantHours;

use App\Filament\Resources\RestaurantHours\Pages\CreateRestaurantHour;
use App\Filament\Resources\RestaurantHours\Pages\EditRestaurantHour;
use App\Filament\Resources\RestaurantHours\Pages\ListRestaurantHours;
use App\Filament\Resources\RestaurantHours\Schemas\RestaurantHourForm;
use App\Filament\Resources\RestaurantHours\Tables\RestaurantHoursTable;
use App\Models\RestaurantHour;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RestaurantHourResource extends Resource
{
    protected static ?string $model = RestaurantHour::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Opening Hours';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return RestaurantHourForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RestaurantHoursTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRestaurantHours::route('/'),
            'create' => CreateRestaurantHour::route('/create'),
            'edit' => EditRestaurantHour::route('/{record}/edit'),
        ];
    }

    /**
     * RestaurantHour has no BelongsToRestaurant global scope — it is
     * deliberately public reference data — so this panel's tenant filter has
     * to be applied by hand instead of coming from a trait.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('restaurant_id', Filament::getTenant()?->getKey());
    }
}
