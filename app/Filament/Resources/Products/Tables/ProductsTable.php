<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Tables;

use App\Models\Category;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                ImageColumn::make('image_path')
                    ->label('')
                    ->disk(config('filesystems.default'))
                    ->square(),

                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn ($record): ?string => $record->description
                        ? str($record->description)->limit(60)->value()
                        : null),

                TextColumn::make('category.name')
                    ->badge()
                    ->sortable(),

                // Money is stored in paise; the cast gives a Money object whose
                // toMajorString() is already fixed-point.
                TextColumn::make('base_price')
                    ->label('Price')
                    ->formatStateUsing(fn ($state): string => '₹'.$state->toMajorString())
                    ->sortable()
                    ->alignEnd(),

                IconColumn::make('is_veg')
                    ->label('Veg')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),

                // The toggle a shift worker uses all day: one click, no form.
                ToggleColumn::make('is_available')->label('In stock'),

                ToggleColumn::make('is_active')
                    ->label('On menu')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category_id')
                    ->label('Category')
                    ->options(fn (): array => Category::query()->orderBy('name')->pluck('name', 'id')->all()),
                TernaryFilter::make('is_available')->label('In stock'),
                TernaryFilter::make('is_veg')->label('Vegetarian'),
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
            ->emptyStateHeading('No products yet')
            ->emptyStateDescription('Create a category first, then add items to it.');
    }
}
