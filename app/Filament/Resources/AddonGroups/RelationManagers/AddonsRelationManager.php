<?php

declare(strict_types=1);

namespace App\Filament\Resources\AddonGroups\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class AddonsRelationManager extends RelationManager
{
    protected static string $relationship = 'addons';

    protected static ?string $title = 'Options';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->helperText('E.g. "Coke", "Extra cheese".')
                    ->columnSpanFull(),

                FileUpload::make('image_path')
                    ->label('Photo')
                    ->image()
                    ->disk('public')
                    ->directory('addons')
                    ->maxSize(4096)
                    ->imageEditor()
                    ->columnSpanFull(),

                self::money('price', 'Price')
                    ->default('0.00')
                    ->required()
                    ->helperText('Entered in rupees. Use 0 for a free option.'),

                self::money('compare_at_price', 'Was (strike-through)')
                    ->helperText('Optional. Shown crossed out next to the price.'),

                TextInput::make('sort_order')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->maxValue(9999),

                Toggle::make('is_available')
                    ->default(true)
                    ->helperText('Switch off when this option runs out.'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                ImageColumn::make('image_path')
                    ->label('')
                    ->disk('public')
                    ->circular(),

                TextColumn::make('name')
                    ->searchable()
                    ->weight('medium'),

                TextColumn::make('price')
                    ->formatStateUsing(fn ($state): string => '₹'.$state->toMajorString())
                    ->alignEnd(),

                TextColumn::make('compare_at_price')
                    ->label('Was')
                    ->formatStateUsing(fn ($state): ?string => $state === null ? null : '₹'.$state->toMajorString())
                    ->color('gray')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                ToggleColumn::make('is_available')
                    ->label('Available'),
            ])
            ->headerActions([
                CreateAction::make(),
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
            ->emptyStateHeading('No options yet')
            ->emptyStateDescription('Add the choices customers pick from, e.g. "Small", "Medium", "Large".');
    }

    private static function money(string $field, string $label): TextInput
    {
        return TextInput::make($field)
            ->label($label)
            ->prefix('₹')
            ->minValue(0)
            ->formatStateUsing(fn ($state): ?string => $state === null
                ? null
                : number_format((int) (is_object($state) ? $state->minor : $state) / 100, 2, '.', ''))
            ->dehydrateStateUsing(fn ($state) => $state === null || $state === ''
                ? null
                : (int) round(((float) $state) * 100));
    }
}
