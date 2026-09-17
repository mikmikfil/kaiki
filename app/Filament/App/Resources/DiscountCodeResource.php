<?php

declare(strict_types=1);

namespace App\Filament\App\Resources;

use App\Domain\Pricing\Actions\ApplyDiscountCode;
use App\Enums\DiscountKind;
use App\Filament\App\Resources\DiscountCodeResource\Pages;
use App\Filament\Forms\MoneyInput;
use App\Models\DiscountCode;
use App\Models\Product;
use App\Support\Format\MoneyFormatter;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

/**
 * «Κουπόνια» — discount codes (product owner, 2026-09-17).
 *
 * Each has an «Εσωτερικό όνομα» the operator recognises and a code the guest
 * types; a percentage or a fixed amount; optional dates, a use limit and a
 * single trip; and a switch. The list says how often each was used and what
 * the bookings that used it came to, which is the question a code is made to
 * answer: did the newsletter pay for itself?
 *
 * Why these are not vouchers is {@see DiscountCode}'s docblock; how one is
 * applied is {@see ApplyDiscountCode}.
 */
class DiscountCodeResource extends Resource
{
    protected static ?string $model = DiscountCode::class;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?int $navigationSort = 31;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.sales');
    }

    public static function getNavigationLabel(): string
    {
        return __('discount_codes.nav');
    }

    public static function getModelLabel(): string
    {
        return __('discount_codes.model.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('discount_codes.model.plural');
    }

    public static function form(Form $form): Form
    {
        $isFixed = static fn (Get $get): bool => $get('kind') === DiscountKind::Fixed->value;

        return $form->schema([
            Section::make()
                ->schema([
                    TextInput::make('name')
                        ->label(__('discount_codes.fields.name'))
                        ->helperText(__('discount_codes.fields.name_help'))
                        ->required()
                        ->maxLength(120),

                    TextInput::make('code')
                        ->label(__('discount_codes.fields.code'))
                        ->helperText(__('discount_codes.fields.code_help'))
                        ->required()
                        ->maxLength(32)
                        ->regex('/^[\pL\pN_-]+$/u')
                        ->dehydrateStateUsing(static fn (?string $state): string => DiscountCode::normalise((string) $state))
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: static fn (Unique $rule, ?string $state): Unique => $rule
                                ->where('code', DiscountCode::normalise((string) $state))
                                ->whereNull('deleted_at'),
                        ),

                    Radio::make('kind')
                        ->label(__('discount_codes.fields.kind'))
                        ->options(DiscountKind::options())
                        ->default(DiscountKind::Percent->value)
                        ->inline()
                        ->required()
                        ->live(),

                    TextInput::make('value')
                        ->label(__('discount_codes.fields.percent'))
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(100)
                        ->suffix('%')
                        ->required(static fn (Get $get): bool => ! $isFixed($get))
                        ->visible(static fn (Get $get): bool => ! $isFixed($get)),

                    MoneyInput::make('value_cents', __('discount_codes.fields.amount'), null)
                        ->required($isFixed)
                        ->visible($isFixed),

                    DatePicker::make('valid_from')->label(__('discount_codes.fields.valid_from')),
                    DatePicker::make('valid_until')
                        ->label(__('discount_codes.fields.valid_until'))
                        ->afterOrEqual('valid_from'),

                    TextInput::make('max_uses')
                        ->label(__('discount_codes.fields.max_uses'))
                        ->helperText(__('discount_codes.fields.max_uses_help'))
                        ->numeric()
                        ->integer()
                        ->minValue(1),

                    Select::make('product_id')
                        ->label(__('discount_codes.fields.product'))
                        ->helperText(__('discount_codes.fields.product_help'))
                        ->options(static fn (): array => Product::query()->get()->mapWithKeys(
                            static fn (Product $product): array => [$product->getKey() => (string) $product->title],
                        )->all())
                        ->searchable(),

                    Toggle::make('is_active')
                        ->label(__('discount_codes.fields.is_active'))
                        ->default(true),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => self::withFigures($query))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label(__('discount_codes.fields.name'))->searchable(),
                TextColumn::make('code')->label(__('discount_codes.fields.code'))->searchable()->copyable()->fontFamily('mono'),
                TextColumn::make('value')
                    ->label(__('discount_codes.fields.discount'))
                    ->state(static fn (DiscountCode $record): string => self::describe($record)),
                TextColumn::make('product.title')
                    ->label(__('discount_codes.fields.product'))
                    ->placeholder(__('discount_codes.all_trips')),
                TextColumn::make('valid_until')->label(__('discount_codes.fields.valid_until'))->date()->placeholder('—'),
                TextColumn::make('uses')
                    ->label(__('discount_codes.columns.uses'))
                    ->state(static fn (DiscountCode $record): string => $record->max_uses === null
                        ? (string) (int) $record->getAttribute('uses')
                        : (int) $record->getAttribute('uses') . ' / ' . $record->max_uses)
                    ->alignEnd(),
                TextColumn::make('revenue')
                    ->label(__('discount_codes.columns.revenue'))
                    ->state(static fn (DiscountCode $record): string => MoneyFormatter::format((int) $record->getAttribute('revenue')))
                    ->alignEnd(),
                IconColumn::make('is_active')->label(__('discount_codes.fields.is_active'))->boolean(),
            ])
            ->emptyStateHeading(__('discount_codes.empty'))
            ->emptyStateDescription(__('discount_codes.empty_help'));
    }

    /**
     * «Χρήσεις» and «Έσοδα που έφερε», from the bookings that happened.
     *
     * @param  Builder<DiscountCode>  $query
     * @return Builder<DiscountCode>
     */
    public static function withFigures(Builder $query): Builder
    {
        $used = static fn ($bookings) => $bookings
            ->where('is_test', false)
            ->whereIn('status', DiscountCode::usedStatuses());

        return $query
            ->withCount(['bookings as uses' => $used])
            ->withSum(['bookings as revenue' => $used], 'total_cents');
    }

    /** «10%» or «20,00 €». */
    public static function describe(DiscountCode $code): string
    {
        return $code->kind === DiscountKind::Percent
            ? $code->value . '%'
            : MoneyFormatter::format($code->value);
    }

    /**
     * The form's two value fields into the one column.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function valueIntoColumn(array $data): array
    {
        if (($data['kind'] ?? null) === DiscountKind::Fixed->value) {
            $data['value'] = (int) ($data['value_cents'] ?? 0);
        }

        unset($data['value_cents']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function valueIntoForm(array $data): array
    {
        if (($data['kind'] ?? null) === DiscountKind::Fixed->value) {
            $data['value_cents'] = $data['value'] ?? null;
            $data['value'] = null;
        }

        return $data;
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDiscountCodes::route('/'),
            'create' => Pages\CreateDiscountCode::route('/create'),
            'edit' => Pages\EditDiscountCode::route('/{record}/edit'),
        ];
    }
}
