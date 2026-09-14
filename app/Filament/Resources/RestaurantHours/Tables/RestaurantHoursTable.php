<?php

declare(strict_types=1);

namespace App\Filament\Resources\RestaurantHours\Tables;

use App\Models\RestaurantHour;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class RestaurantHoursTable
{
    private const DAYS = [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('day_of_week')
            ->columns([
                TextColumn::make('day_of_week')
                    ->label('Day')
                    ->formatStateUsing(fn (int $state): string => self::DAYS[$state])
                    ->weight('medium'),

                TextColumn::make('opens_at')
                    ->label('Opens')
                    ->time('H:i')
                    ->placeholder('—'),

                TextColumn::make('closes_at')
                    ->label('Closes')
                    ->time('H:i')
                    ->placeholder('—'),

                TextColumn::make('closes_at')
                    ->label('')
                    ->state(fn (RestaurantHour $record): ?string => $record->crossesMidnight() ? 'Past midnight' : null)
                    ->badge()
                    ->color('warning'),

                ToggleColumn::make('is_closed')
                    ->label('Closed'),
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
            ->emptyStateHeading('No opening hours yet')
            ->emptyStateDescription('Add a slot for each day you are open — add a second row for split service like lunch and dinner.');
    }
}
