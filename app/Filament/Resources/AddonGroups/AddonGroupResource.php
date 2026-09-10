<?php

namespace App\Filament\Resources\AddonGroups;

use App\Filament\Resources\AddonGroups\Pages\CreateAddonGroup;
use App\Filament\Resources\AddonGroups\Pages\EditAddonGroup;
use App\Filament\Resources\AddonGroups\Pages\ListAddonGroups;
use App\Filament\Resources\AddonGroups\RelationManagers\AddonsRelationManager;
use App\Filament\Resources\AddonGroups\Schemas\AddonGroupForm;
use App\Filament\Resources\AddonGroups\Tables\AddonGroupsTable;
use App\Models\AddonGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class AddonGroupResource extends Resource
{
    protected static ?string $model = AddonGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquaresPlus;

    protected static ?string $navigationLabel = 'Add-on Groups';

    protected static ?int $navigationSort = 3;

    protected static string|\UnitEnum|null $navigationGroup = 'Menu';

    public static function form(Schema $schema): Schema
    {
        return AddonGroupForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AddonGroupsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            AddonsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAddonGroups::route('/'),
            'create' => CreateAddonGroup::route('/create'),
            'edit' => EditAddonGroup::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
