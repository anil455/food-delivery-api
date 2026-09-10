<?php

declare(strict_types=1);

namespace App\Filament\Resources\AddonGroups\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class AddonGroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn ($record): ?string => $record->description
                        ? str($record->description)->limit(60)->value()
                        : null),

                TextColumn::make('addons_count')
                    ->label('Options')
                    ->counts('addons')
                    ->badge()
                    ->alignCenter(),

                TextColumn::make('products_count')
                    ->label('Used on')
                    ->counts('products')
                    ->badge()
                    ->color('gray')
                    ->alignCenter()
                    ->suffix(' products'),

                TextColumn::make('min_select')
                    ->label('Min')
                    ->alignCenter(),

                TextColumn::make('max_select')
                    ->label('Max')
                    ->alignCenter(),

                IconColumn::make('is_required')
                    ->boolean()
                    ->alignCenter(),

                ToggleColumn::make('is_active')
                    ->label('Active'),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
                TernaryFilter::make('is_required')->label('Required'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No add-on groups yet')
            ->emptyStateDescription('Create a group (e.g. "Choice of drink"), add options inside it, then attach it to products.');
    }
}
