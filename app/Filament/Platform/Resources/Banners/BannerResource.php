<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Banners;

use App\Filament\Platform\Resources\Banners\Pages\CreateBanner;
use App\Filament\Platform\Resources\Banners\Pages\EditBanner;
use App\Filament\Platform\Resources\Banners\Pages\ListBanners;
use App\Models\Banner;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Platform-wide banners — shown to every customer regardless of location.
 * A restaurant's own promotional banners live in the restaurant admin panel
 * instead; this resource only ever sees restaurant_id = null.
 */
class BannerResource extends Resource
{
    protected static ?string $model = Banner::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?string $navigationLabel = 'Banners';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Banner')
                ->columns(2)
                ->schema([
                    TextInput::make('title')->maxLength(255)->columnSpanFull(),
                    TextInput::make('subtitle')->maxLength(255)->columnSpanFull(),

                    FileUpload::make('image_path')
                        ->label('Image')
                        ->required()
                        ->image()
                        ->disk(config('filesystems.default'))
                        ->directory('platform/banners')
                        ->maxSize(4096)
                        ->imageEditor()
                        ->helperText('Recommended: wide banner, at least 1200×400.')
                        ->columnSpanFull(),
                ]),

            Section::make('Link')
                ->columns(2)
                ->description('Where tapping the banner takes the customer. Leave as None for a purely informational banner.')
                ->schema([
                    Select::make('link_type')
                        ->options(['url' => 'External URL'])
                        ->native(false)
                        ->live(),
                    TextInput::make('link_value')
                        ->label('URL')
                        ->url()
                        ->maxLength(255)
                        ->visible(fn ($get) => $get('link_type') === 'url')
                        ->required(fn ($get) => $get('link_type') === 'url'),
                ]),

            Section::make('Schedule')
                ->columns(3)
                ->schema([
                    DateTimePicker::make('starts_at')
                        ->label('Starts')
                        ->helperText('Blank starts immediately.'),
                    DateTimePicker::make('ends_at')
                        ->label('Ends')
                        ->after('starts_at')
                        ->helperText('Blank runs indefinitely.'),
                    TextInput::make('sort_order')
                        ->numeric()->default(0)->minValue(0)->maxValue(9999)
                        ->helperText('Lower numbers appear first.'),
                ]),

            Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                ImageColumn::make('image_path')
                    ->label('')
                    ->disk(config('filesystems.default')),

                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn ($record): ?string => $record->subtitle),

                TextColumn::make('link_value')
                    ->label('Links to')
                    ->limit(30)
                    ->placeholder('—'),

                ToggleColumn::make('is_active')
                    ->label('Active'),

                TextColumn::make('updated_at')
                    ->dateTime('d M Y, H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
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
            ->emptyStateHeading('No platform banners yet')
            ->emptyStateDescription('These show to every customer, everywhere — restaurant-specific promotions live inside each restaurant\'s own admin panel.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBanners::route('/'),
            'create' => CreateBanner::route('/create'),
            'edit' => EditBanner::route('/{record}/edit'),
        ];
    }

    /** Only ever platform banners — a restaurant's own banners are managed from its own panel. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNull('restaurant_id');
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
