<?php

declare(strict_types=1);

namespace App\Filament\Pages\Tenancy;

use App\Enums\StaffRole;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The restaurant's own profile, edited from inside the panel.
 *
 * Deliberately excludes status and commission_rate: those are platform
 * decisions, guarded on the model, and live only in the platform panel. A
 * restaurant cannot approve or un-suspend itself.
 */
class RestaurantProfile extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return 'Restaurant settings';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Details')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(255),
                    TextInput::make('phone')->required()->tel()->maxLength(20),
                    TextInput::make('email')->email()->maxLength(255),
                    Select::make('timezone')
                        ->options(array_combine(
                            timezone_identifiers_list(),
                            timezone_identifiers_list(),
                        ))
                        ->searchable()
                        ->required()
                        ->helperText('Opening hours are judged in this timezone.'),
                    Textarea::make('description')->rows(3)->columnSpanFull()->maxLength(1000),
                ]),

            Section::make('Location and delivery')
                ->columns(2)
                ->schema([
                    TextInput::make('address_line')->required()->maxLength(255)->columnSpanFull(),
                    TextInput::make('landmark')->maxLength(255),
                    TextInput::make('city')->required()->maxLength(100),
                    TextInput::make('state')->required()->maxLength(100),
                    TextInput::make('postal_code')->required()->maxLength(20),
                    TextInput::make('latitude')
                        ->required()->numeric()->minValue(-90)->maxValue(90)
                        ->helperText('Used by nearby search. Get it from Google Maps.'),
                    TextInput::make('longitude')
                        ->required()->numeric()->minValue(-180)->maxValue(180),
                    TextInput::make('delivery_radius_km')
                        ->label('Delivery radius (km)')
                        ->required()->numeric()->minValue(0.1)->maxValue(50)
                        ->helperText('Customers beyond this are shown the restaurant but cannot order.'),
                ]),

            Section::make('Charges')
                ->columns(2)
                ->description('Entered in rupees. Stored internally as paise, so nothing is ever a float.')
                ->schema([
                    self::money('min_order_amount', 'Minimum order'),
                    self::money('delivery_fee_base', 'Delivery fee (base)'),
                    self::money('delivery_fee_per_km', 'Delivery fee per km'),
                    self::money('packaging_fee', 'Packaging fee'),
                    TextInput::make('tax_percentage')
                        ->label('Tax %')->numeric()->minValue(0)->maxValue(100)->suffix('%'),
                    TextInput::make('avg_prep_time_minutes')
                        ->label('Average prep time')->numeric()->minValue(1)->maxValue(240)->suffix('min'),
                ]),

            Section::make('Availability')
                ->schema([
                    Toggle::make('is_accepting_orders')
                        ->label('Accepting orders')
                        ->helperText('Switch off when the kitchen is swamped. Overrides the schedule.'),
                ]),
        ]);
    }

    /**
     * Money is stored in paise. The form shows rupees and converts on the way
     * in and out, so nobody has to think in minor units to edit a price.
     */
    private static function money(string $field, string $label): TextInput
    {
        return TextInput::make($field)
            ->label($label)
            ->prefix('₹')
            ->minValue(0)
            ->formatStateUsing(fn ($state): ?string => $state === null
                ? null
                : number_format((int) (is_object($state) ? $state->minor : $state) / 100, 2, '.', ''))
            ->dehydrateStateUsing(fn ($state): int => (int) round(((float) $state) * 100));
    }

    /** Only managers and owners may change the restaurant profile. */
    public static function canView(\Illuminate\Database\Eloquent\Model $tenant): bool
    {
        $user = Filament::auth()->user();

        return $user !== null && $user->hasRoleInRestaurant($tenant, StaffRole::Manager);
    }
}
