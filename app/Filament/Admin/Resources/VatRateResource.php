<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\VatRateResource\Pages;
use App\Filament\Forms\TranslatableInput;
use App\Models\VatRate;
use App\Policies\VatRatePolicy;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The platform's VAT rate table (spec CAT-11, ADR-0002 Option A).
 *
 * Lives in `/admin` and nowhere else. VAT rates are set by Greek tax law, so a
 * super-admin maintains the rows and an operator only *selects* one per product
 * or extra — {@see VatRatePolicy} refuses an operator even the read.
 *
 * ## The form locks the two columns that must not be rewritten
 *
 * `rate_bp` and `valid_from` are disabled once a row exists. A statutory change
 * is a **new row** with a later `valid_from` (§2.3), never an edit — editing the
 * percentage in place would change what every product currently pointing at
 * this row resolves to, silently and retroactively. Price snapshots protect
 * issued invoices downstream, but the reference table should not be lying
 * either. What an edit *is* for: fixing a description, and retiring a rate.
 *
 * ## No delete action
 *
 * §2.3 has no soft deletes and the foreign keys are `restrictOnDelete`, so the
 * only thing a delete button could produce is a constraint violation on screen.
 * Retiring is `is_selectable`, which hides a superseded rate from the product
 * form while every existing reference still resolves.
 *
 * ## No percentage appears in this file
 *
 * CAT-11a: no rate literal and no percent-to-category mapping anywhere in
 * `app/`. The suffix on the rate field is a unit, the helper text explains the
 * unit, and `NoHardcodedVatRateTest` scans this directory to keep it that way.
 */
class VatRateResource extends Resource
{
    protected static ?string $model = VatRate::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?int $navigationSort = 20;

    public static function getNavigationLabel(): string
    {
        return __('vat.nav');
    }

    public static function getModelLabel(): string
    {
        return __('vat.model.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('vat.model.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema(static::formSchema());
    }

    /** @return array<int, Component> */
    public static function formSchema(): array
    {
        return [
            Section::make(__('vat.sections.identity'))
                ->schema([
                    TextInput::make('code')
                        ->label(__('vat.form.code.label'))
                        ->helperText(__('vat.form.code.help'))
                        ->required()
                        ->maxLength(32)
                        // Unique with `valid_from`, matching the index: the same
                        // code recurs every time the rate changes.
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where(
                            'valid_from',
                            request()->input('data.valid_from'),
                        )),

                    TextInput::make('rate_bp')
                        ->label(__('vat.form.rate_bp.label'))
                        ->helperText(__('vat.form.rate_bp.help'))
                        ->integer()
                        ->required()
                        ->minValue(0)
                        // The column is an unsigned smallint; 10000 basis points
                        // is 100%, which is the ceiling any rate can have.
                        ->maxValue(10000)
                        ->suffix(__('vat.form.rate_bp.suffix'))
                        // Locked on edit — a statutory change is a new row.
                        ->disabled(fn (?VatRate $record): bool => $record !== null)
                        ->dehydrated(fn (?VatRate $record): bool => $record === null),

                    TextInput::make('vat_category')
                        ->label(__('vat.form.vat_category.label'))
                        ->helperText(__('vat.form.vat_category.help'))
                        ->required()
                        ->maxLength(16),

                    TranslatableInput::text(
                        'description',
                        __('vat.form.description.label'),
                        __('vat.form.description.help'),
                        maxLength: 120,
                    ),
                ])
                ->columns(2),

            Section::make(__('vat.sections.validity'))
                ->schema([
                    DatePicker::make('valid_from')
                        ->label(__('vat.form.valid_from.label'))
                        ->helperText(__('vat.form.valid_from.help'))
                        ->required()
                        ->native(false)
                        ->disabled(fn (?VatRate $record): bool => $record !== null)
                        ->dehydrated(fn (?VatRate $record): bool => $record === null),

                    DatePicker::make('valid_to')
                        ->label(__('vat.form.valid_to.label'))
                        ->helperText(__('vat.form.valid_to.help'))
                        ->native(false)
                        ->afterOrEqual('valid_from'),

                    Toggle::make('is_selectable')
                        ->label(__('vat.form.is_selectable.label'))
                        ->helperText(__('vat.form.is_selectable.help'))
                        ->default(true),
                ])
                ->columns(2),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label(__('vat.table.code'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('rate_bp')
                    ->label(__('vat.table.rate'))
                    // Rendered through the model, so "13.00%" is formatted in
                    // one place rather than by every screen dividing by a
                    // hundred slightly differently.
                    ->state(fn (VatRate $record): string => $record->percentLabel())
                    ->sortable(),

                TextColumn::make('vat_category')
                    ->label(__('vat.table.vat_category'))
                    ->badge(),

                TextColumn::make('description')
                    ->label(__('vat.table.description'))
                    ->wrap()
                    ->toggleable(),

                TextColumn::make('valid_from')
                    ->label(__('vat.table.valid_from'))
                    ->date()
                    ->sortable(),

                TextColumn::make('valid_to')
                    ->label(__('vat.table.valid_to'))
                    ->date()
                    ->placeholder(__('vat.table.in_force'))
                    ->sortable(),

                IconColumn::make('is_selectable')
                    ->label(__('vat.table.is_selectable'))
                    ->boolean(),
            ])
            ->defaultSort('valid_from', 'desc')
            ->actions([
                EditAction::make(),
                // No DeleteAction: rows are superseded, never removed (§2.3).
            ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVatRates::route('/'),
            'create' => Pages\CreateVatRate::route('/create'),
            'edit' => Pages\EditVatRate::route('/{record}/edit'),
        ];
    }
}
