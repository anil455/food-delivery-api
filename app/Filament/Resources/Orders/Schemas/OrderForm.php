<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('order_number')
                    ->required(),
                TextInput::make('idempotency_key')
                    ->default(null),
                Select::make('user_id')
                    ->relationship('user', 'name')
                    ->required(),
                Select::make('restaurant_id')
                    ->relationship('restaurant', 'name')
                    ->required(),
                Select::make('address_id')
                    ->relationship('address', 'id')
                    ->default(null),
                Textarea::make('delivery_address')
                    ->required()
                    ->columnSpanFull(),
                TextInput::make('customer_name')
                    ->default(null),
                TextInput::make('customer_phone')
                    ->tel()
                    ->required(),
                Select::make('status')
                    ->options(OrderStatus::class)
                    ->default('pending')
                    ->required(),
                Select::make('payment_status')
                    ->options(PaymentStatus::class)
                    ->default('pending')
                    ->required(),
                TextInput::make('payment_method')
                    ->default(null),
                TextInput::make('subtotal')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('discount_total')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('delivery_fee')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('packaging_fee')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('tax_total')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('tip')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('grand_total')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('currency')
                    ->required()
                    ->default('INR'),
                Select::make('coupon_id')
                    ->relationship('coupon', 'id')
                    ->default(null),
                TextInput::make('coupon_code')
                    ->default(null),
                TextInput::make('distance_km')
                    ->numeric()
                    ->default(null),
                TextInput::make('estimated_minutes')
                    ->numeric()
                    ->default(null),
                TextInput::make('special_instructions')
                    ->default(null),
                DateTimePicker::make('scheduled_for'),
                DateTimePicker::make('placed_at'),
                DateTimePicker::make('confirmed_at'),
                DateTimePicker::make('delivered_at'),
                DateTimePicker::make('cancelled_at'),
                TextInput::make('cancellation_reason')
                    ->default(null),
                TextInput::make('cancelled_by_user_id')
                    ->numeric()
                    ->default(null),
            ]);
    }
}
