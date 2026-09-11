<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Restaurants;

use App\Enums\RestaurantStatus;
use App\Filament\Platform\Resources\Restaurants\Pages\CreateRestaurant;
use App\Filament\Platform\Resources\Restaurants\Pages\EditRestaurant;
use App\Filament\Platform\Resources\Restaurants\Pages\ListRestaurants;
use App\Models\Restaurant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RestaurantResource extends Resource
{
    protected static ?string $model = Restaurant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $navigationLabel = 'Restaurants';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Restaurant')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(255),
                    TextInput::make('phone')->required()->tel()->maxLength(20),
                    TextInput::make('email')->email()->maxLength(255),
                    Select::make('timezone')
                        ->options(array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                        ->default('Asia/Kolkata')
                        ->searchable()
                        ->required(),
                ]),

            Section::make('Images')
                ->columns(2)
                ->schema([
                    FileUpload::make('logo_path')
                        ->label('Logo')
                        ->image()
                        ->disk(config('filesystems.default'))
                        ->directory('restaurants/logos')
                        ->maxSize(4096)
                        ->imageEditor(),
                    FileUpload::make('cover_path')
                        ->label('Cover image')
                        ->image()
                        ->disk(config('filesystems.default'))
                        ->directory('restaurants/covers')
                        ->maxSize(4096)
                        ->imageEditor(),
                ]),

            Section::make('Location')
                ->columns(2)
                ->schema([
                    TextInput::make('address_line')->required()->maxLength(255)->columnSpanFull(),
                    TextInput::make('city')->required()->maxLength(100),
                    TextInput::make('state')->required()->maxLength(100),
                    TextInput::make('postal_code')->required()->maxLength(20),
                    TextInput::make('delivery_radius_km')
                        ->label('Delivery radius (km)')->numeric()->default(5)->minValue(0.1)->maxValue(50),
                    TextInput::make('latitude')
                        ->required()->numeric()->minValue(-90)->maxValue(90)
                        ->helperText('Nearby search depends on this. Copy it from Google Maps.'),
                    TextInput::make('longitude')->required()->numeric()->minValue(-180)->maxValue(180),
                ]),

            Section::make('Platform settings')
                ->columns(2)
                ->description('Only the platform sets these. Restaurant staff cannot change them.')
                ->schema([
                    Select::make('status')
                        ->options(collect(RestaurantStatus::cases())
                            ->mapWithKeys(fn (RestaurantStatus $s): array => [$s->value => $s->label()])
                            ->all())
                        ->default(RestaurantStatus::PendingApproval->value)
                        ->required()
                        ->native(false)
                        ->helperText('Only Active restaurants appear in customer search.'),
                    TextInput::make('commission_rate')
                        ->label('Commission')->numeric()->default(18)->minValue(0)->maxValue(100)->suffix('%'),
                ]),

            /*
             * A restaurant with no owner is unmanageable, so the first owner is
             * created in the same transaction. Shown only on create; afterwards
             * staff are managed from inside the restaurant panel.
             */
            Section::make('First owner')
                ->columns(2)
                ->description('Creates the login that will run this restaurant.')
                ->visibleOn('create')
                ->schema([
                    TextInput::make('owner_name')->label('Name')->required()->maxLength(255),
                    TextInput::make('owner_email')
                        ->label('Email')->email()->required()->maxLength(255)
                        ->unique(table: 'users', column: 'email'),
                    TextInput::make('owner_password')
                        ->label('Password')->password()->required()->minLength(8)
                        ->revealable()
                        ->helperText('Share it with the owner; they can change it later.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                ImageColumn::make('logo_path')
                    ->label('')
                    ->disk(config('filesystems.default'))
                    ->circular(),

                TextColumn::make('name')->searchable()->sortable()->weight('medium')
                    ->description(fn (Restaurant $r): string => $r->city),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (RestaurantStatus $state): string => $state->label())
                    ->color(fn (RestaurantStatus $state): string => match ($state) {
                        RestaurantStatus::Active => 'success',
                        RestaurantStatus::PendingApproval => 'warning',
                        RestaurantStatus::Inactive => 'gray',
                        RestaurantStatus::Suspended => 'danger',
                    }),

                TextColumn::make('products_count')->label('Products')->counts('products')->alignCenter(),
                TextColumn::make('orders_count')->label('Orders')->counts('orders')->alignCenter(),
                TextColumn::make('commission_rate')->label('Commission')->suffix('%')->alignEnd(),
                TextColumn::make('phone')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(RestaurantStatus::cases())
                    ->mapWithKeys(fn (RestaurantStatus $s): array => [$s->value => $s->label()])
                    ->all()),
            ])
            ->recordActions([
                // The onboarding step: a new restaurant is invisible to
                // customers until someone here approves it.
                Action::make('approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('The restaurant becomes visible to customers immediately.')
                    ->visible(fn (Restaurant $r): bool => $r->status !== RestaurantStatus::Active)
                    ->action(function (Restaurant $record): void {
                        $record->forceFill(['status' => RestaurantStatus::Active])->save();

                        Notification::make()->title("{$record->name} is now live")->success()->send();
                    }),

                Action::make('suspend')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Drops it out of customer search and blocks staff writes. Data is kept.')
                    ->visible(fn (Restaurant $r): bool => $r->status === RestaurantStatus::Active)
                    ->action(function (Restaurant $record): void {
                        $record->forceFill(['status' => RestaurantStatus::Suspended])->save();

                        Notification::make()->title("{$record->name} suspended")->warning()->send();
                    }),

                EditAction::make(),
            ])
            ->emptyStateHeading('No restaurants yet')
            ->emptyStateDescription('Create the first one, and its owner login, with the button above.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRestaurants::route('/'),
            'create' => CreateRestaurant::route('/create'),
            'edit' => EditRestaurant::route('/{record}/edit'),
        ];
    }
}
