<?php

declare(strict_types=1);

namespace App\Filament\Resources\RestaurantHours\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class RestaurantHourForm
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

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                /*
                 * No restaurant picker: the tenant comes from the panel URL,
                 * the same way BelongsToRestaurant would stamp it if this
                 * model used that trait (it deliberately does not).
                 */
                Select::make('day_of_week')
                    ->options(fn (string $operation): array => $operation === 'create'
                        ? ['all' => 'All week (same hours every day)', ...self::DAYS]
                        : self::DAYS)
                    ->required()
                    ->helperText('Pick "All week" to create one row per day at once, or add a second row for the same day to express split service, e.g. lunch then dinner.'),

                Toggle::make('is_closed')
                    ->label('Closed all day')
                    ->live()
                    ->default(false),

                TimePicker::make('opens_at')
                    ->seconds(false)
                    ->required(fn (Get $get): bool => ! $get('is_closed'))
                    ->visible(fn (Get $get): bool => ! $get('is_closed')),

                TimePicker::make('closes_at')
                    ->seconds(false)
                    ->required(fn (Get $get): bool => ! $get('is_closed'))
                    ->visible(fn (Get $get): bool => ! $get('is_closed'))
                    ->helperText('Same as or before opens time means the slot runs past midnight.'),
            ]);
    }
}
