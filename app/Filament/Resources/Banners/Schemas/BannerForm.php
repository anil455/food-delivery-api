<?php

declare(strict_types=1);

namespace App\Filament\Resources\Banners\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class BannerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                /*
                 * No restaurant picker and no link fields: a restaurant banner
                 * always belongs to the tenant resolved from the URL (stamped
                 * in CreateBanner) and always deep-links to that restaurant's
                 * own menu — there is nowhere else it could sensibly point.
                 */
                TextInput::make('title')->maxLength(255)->columnSpanFull(),

                TextInput::make('subtitle')->maxLength(255)->columnSpanFull(),

                FileUpload::make('image_path')
                    ->label('Image')
                    ->required()
                    ->image()
                    ->disk(config('filesystems.default'))
                    ->directory('banners')
                    ->maxSize(4096)
                    ->imageEditor()
                    ->helperText('Recommended: wide banner, at least 1200×400.')
                    ->columnSpanFull(),

                DateTimePicker::make('starts_at')
                    ->label('Starts')
                    ->helperText('Leave blank to start showing immediately.'),

                DateTimePicker::make('ends_at')
                    ->label('Ends')
                    ->after('starts_at')
                    ->helperText('Leave blank to run indefinitely.'),

                TextInput::make('sort_order')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->maxValue(9999)
                    ->helperText('Lower numbers appear first in the slider.'),

                Toggle::make('is_active')
                    ->default(true)
                    ->helperText('Switch off to hide it without deleting it.'),
            ]);
    }
}
