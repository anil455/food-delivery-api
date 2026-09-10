<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Order\OrderStatusService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The order board.
 *
 * Read-only apart from one action: advancing the status. Orders are never
 * edited through a form, because every transition has to go through
 * OrderStatusService — that is where the legal-transition table lives and where
 * the audit row gets written.
 */
class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            // Live orders matter now; history can wait for a page refresh.
            ->poll('30s')
            ->columns([
                TextColumn::make('order_number')
                    ->label('Order')
                    ->searchable()
                    ->copyable()
                    ->weight('medium'),

                TextColumn::make('customer_name')
                    ->label('Customer')
                    ->searchable()
                    ->description(fn (Order $record): ?string => $record->customer_phone),

                TextColumn::make('items_count')
                    ->label('Items')
                    ->counts('items')
                    ->alignCenter(),

                TextColumn::make('grand_total')
                    ->label('Total')
                    ->formatStateUsing(fn ($state): string => '₹'.$state->toMajorString())
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (OrderStatus $state): string => $state->label())
                    ->color(fn (OrderStatus $state): string => match ($state) {
                        OrderStatus::Pending => 'warning',
                        OrderStatus::Confirmed, OrderStatus::Preparing => 'info',
                        OrderStatus::ReadyForPickup, OrderStatus::OutForDelivery => 'primary',
                        OrderStatus::Delivered => 'success',
                        OrderStatus::Cancelled, OrderStatus::Rejected => 'danger',
                    }),

                TextColumn::make('payment_status')
                    ->label('Payment')
                    ->badge()
                    ->color(fn ($state): string => $state->isSettled() ? 'success' : 'gray'),

                TextColumn::make('placed_at')
                    ->label('Placed')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(fn (): array => collect(OrderStatus::cases())
                        ->mapWithKeys(fn (OrderStatus $s): array => [$s->value => $s->label()])
                        ->all()),

                SelectFilter::make('live')
                    ->label('Live orders only')
                    ->placeholder('All orders')
                    ->options(['1' => 'Live orders only'])
                    ->query(fn (Builder $query, array $data): Builder => ($data['value'] ?? null) === '1'
                        ? $query->active()
                        : $query),
            ])
            ->recordActions([
                ViewAction::make(),

                Action::make('advance')
                    ->label('Change status')
                    ->icon('heroicon-o-arrow-right-circle')
                    ->visible(fn (Order $record): bool => ! $record->status->isTerminal())
                    ->schema(fn (Order $record): array => [
                        Select::make('status')
                            ->label('Move to')
                            // Only the transitions the state machine allows from
                            // here. An illegal move is not offered at all.
                            ->options(collect($record->status->allowedTransitions())
                                ->mapWithKeys(fn (OrderStatus $s): array => [$s->value => $s->label()])
                                ->all())
                            ->required()
                            ->native(false),

                        Textarea::make('note')
                            ->label('Note')
                            ->rows(2)
                            ->maxLength(255)
                            ->helperText('Recorded in the order history. Shown to the customer when cancelling.'),
                    ])
                    ->action(function (Order $record, array $data): void {
                        app(OrderStatusService::class)->transition(
                            $record,
                            OrderStatus::from($data['status']),
                            auth()->user(),
                            $data['note'] ?? null,
                        );

                        Notification::make()
                            ->title('Order updated')
                            ->body("{$record->order_number} is now ".OrderStatus::from($data['status'])->label().'.')
                            ->success()
                            ->send();
                    }),
            ])
            ->emptyStateHeading('No orders yet')
            ->emptyStateDescription('Orders placed by customers appear here as they arrive.');
    }
}
