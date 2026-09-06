<?php

declare(strict_types=1);

namespace App\Filament\App\Resources;

use App\Domain\Booking\Actions\BuildQuote;
use App\Domain\Booking\Actions\SendQuote;
use App\Enums\QuoteLineKind;
use App\Enums\QuoteStatus;
use App\Filament\App\Resources\QuoteResource\Pages;
use App\Models\Quote;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Building and sending an offer, on `/app` (spec BKG-24…27, TEN-8, SEC-3).
 *
 * Thin (CNV-5). Every transition belongs to an Action —
 * {@see BuildQuote} mints a version, {@see SendQuote} sends and supersedes,
 * `AcceptQuote` and `DeclineQuote` belong to the guest — because §4.4's state
 * machine has to hold whether the transition came from this screen, the API or
 * a scheduled sweep.
 *
 * ## Only a draft can be edited, and that is §4.4 rather than a preference
 *
 * A `sent` quote is an offer somebody is holding a link to. Editing it in place
 * would change the price under that link with nothing anywhere saying so, and
 * would destroy the record of what was actually offered. **Revise** therefore
 * creates a new version and supersedes the old one, which is what the transition
 * table means by *"the diagram edge is the operator-visible action, the
 * implementation is create-and-supersede"*.
 *
 * ## The hold is a checkbox that is off
 *
 * BKG-25, and the default is the requirement. A quote reserves nothing; the
 * operator opts in at the moment of sending, and the block that creates expires
 * with the quote. A toggle that defaulted to on would be the indefinite vessel
 * block the requirement was resolved to prevent.
 *
 * ## Amounts are in cents and shown in euros
 *
 * §1.4. The form takes euros because an operator types euros, and the Action
 * stores integers because §1.4 says every money column is an integer. The
 * conversion happens once, here, in {@see self::toCents()} — not in three
 * places that can disagree by a rounding.
 */
class QuoteResource extends Resource
{
    protected static ?string $model = Quote::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-currency-euro';

    protected static ?int $navigationSort = 22;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('quotes.quote.nav');
    }

    public static function getModelLabel(): string
    {
        return __('quotes.quote.model.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('quotes.quote.model.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema(static::formSchema());
    }

    /** @return array<int, Component> */
    public static function formSchema(): array
    {
        return [
            Section::make(__('quotes.quote.sections.lines'))
                ->schema([
                    Repeater::make('lineItems')
                        ->relationship()
                        ->hiddenLabel()
                        ->schema([
                            TextInput::make('label')
                                ->label(__('quotes.quote.form.label.label'))
                                ->required()
                                ->maxLength(190)
                                ->columnSpan(2),

                            Select::make('kind')
                                ->label(__('quotes.quote.form.kind.label'))
                                ->options(QuoteLineKind::options())
                                ->default(QuoteLineKind::Charter->value)
                                ->required(),

                            TextInput::make('qty')
                                ->label(__('quotes.quote.form.qty.label'))
                                ->numeric()
                                ->default(1)
                                ->minValue(1)
                                ->live(onBlur: true)
                                ->afterStateUpdated(static::prefillTotal(...)),

                            TextInput::make('unit_price_cents')
                                ->label(__('quotes.quote.form.unit_price_cents.label'))
                                ->numeric()
                                ->prefix('€')
                                ->default(0)
                                ->live(onBlur: true)
                                ->afterStateUpdated(static::prefillTotal(...))
                                ->formatStateUsing(static::toEuros(...))
                                ->dehydrateStateUsing(static::toCents(...)),

                            TextInput::make('total_cents')
                                ->label(__('quotes.quote.form.total_cents.label'))
                                ->helperText(__('quotes.quote.form.total_cents.help'))
                                ->numeric()
                                ->prefix('€')
                                ->default(0)
                                ->formatStateUsing(static::toEuros(...))
                                ->dehydrateStateUsing(static::toCents(...)),
                        ])
                        ->columns(3)
                        ->orderColumn('sort_order')
                        ->reorderable()
                        ->addActionLabel(__('quotes.quote.sections.lines')),
                ]),

            Section::make(__('quotes.quote.sections.terms'))
                ->schema([
                    DateTimePicker::make('valid_until')
                        ->label(__('quotes.quote.form.valid_until.label'))
                        ->helperText(__('quotes.quote.form.valid_until.help'))
                        ->seconds(false)
                        ->native(false)
                        ->required(),

                    TextInput::make('deposit_cents')
                        ->label(__('quotes.quote.form.deposit_cents.label'))
                        ->helperText(__('quotes.quote.form.deposit_cents.help'))
                        ->numeric()
                        ->prefix('€')
                        ->default(0)
                        ->formatStateUsing(static::toEuros(...))
                        ->dehydrateStateUsing(static::toCents(...)),

                    Textarea::make('message')
                        ->label(__('quotes.quote.form.message.label'))
                        ->helperText(__('quotes.quote.form.message.help'))
                        ->rows(3)
                        ->columnSpanFull(),

                    Textarea::make('terms')
                        ->label(__('quotes.quote.form.terms.label'))
                        ->rows(3)
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('booking.reference')
                    ->label(__('quotes.quote.table.reference'))
                    ->searchable(),

                TextColumn::make('version')
                    ->label(__('quotes.quote.table.version'))
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('quotes.quote.table.status'))
                    ->badge()
                    ->formatStateUsing(static fn (QuoteStatus $state): string => $state->label()),

                TextColumn::make('total_cents')
                    ->label(__('quotes.quote.table.total'))
                    ->money('EUR', divideBy: 100)
                    ->sortable(),

                TextColumn::make('valid_until')
                    ->label(__('quotes.quote.table.valid_until'))
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('sent_at')
                    ->label(__('quotes.quote.table.sent_at'))
                    ->dateTime()
                    ->toggleable(),

                // An operator chasing a quote wants to know whether it was ever
                // looked at. `viewed_at` is stamped by `/q/{token}`'s first
                // successful view (#86).
                TextColumn::make('viewed_at')
                    ->label(__('quotes.quote.table.viewed_at'))
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('quotes.quote.table.status'))
                    ->options(QuoteStatus::options()),
            ])
            ->actions([
                // Drafts only. See the class docblock: a sent quote is an offer
                // somebody holds a link to.
                EditAction::make()
                    ->visible(static fn (Quote $record): bool => $record->status === QuoteStatus::Draft),

                static::sendAction(),
                static::reviseAction(),
            ]);
    }

    /**
     * BKG-26's send, with BKG-25's opt-in beside it.
     *
     * The refusal is a notification rather than an exception page: every one of
     * `SendQuote`'s guards is an operator's own slip — an empty quote, a free
     * charter, an offer already expired — and each deserves a sentence rather
     * than a stack trace.
     */
    public static function sendAction(): Action
    {
        return Action::make('sendQuote')
            ->label(__('quotes.quote.actions.send.label'))
            ->icon('heroicon-o-paper-airplane')
            ->requiresConfirmation()
            ->form([
                Toggle::make('hold_vessel')
                    ->label(__('quotes.quote.actions.send.hold.label'))
                    ->helperText(__('quotes.quote.actions.send.hold.help'))
                    // **Off.** BKG-25's resolution is the default, not the
                    // option: a quote reserves nothing unless somebody says so.
                    ->default(false),
            ])
            ->visible(static fn (Quote $record): bool => $record->status === QuoteStatus::Draft)
            ->action(static function (Quote $record, array $data): void {
                try {
                    app(SendQuote::class)($record, holdVessel: (bool) ($data['hold_vessel'] ?? false));
                } catch (Throwable $exception) {
                    Notification::make()
                        ->danger()
                        ->title(__('quotes.quote.actions.send.refused', ['reason' => $exception->getMessage()]))
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('quotes.quote.actions.send.sent'))
                    ->send();
            });
    }

    /**
     * §4.4's `revise`, which is create-and-supersede rather than an edit.
     *
     * The new version is a `draft` the operator then fills in; the old one is
     * expired by {@see SendQuote} when the new one goes out, so an operator who
     * starts a revision and abandons it has not withdrawn the live offer.
     */
    public static function reviseAction(): Action
    {
        return Action::make('reviseQuote')
            ->label(__('quotes.quote.actions.revise.label'))
            ->icon('heroicon-o-pencil-square')
            ->requiresConfirmation()
            ->modalDescription(__('quotes.quote.actions.revise.help'))
            ->visible(static fn (Quote $record): bool => $record->status === QuoteStatus::Sent)
            ->action(static function (Quote $record): void {
                $next = app(BuildQuote::class)($record->booking()->firstOrFail());

                Notification::make()
                    ->success()
                    ->title(__('quotes.quote.actions.revise.created', ['version' => $next->version]))
                    ->send();
            });
    }

    /**
     * Fill the line total from quantity × price, without owning it.
     *
     * A convenience, and only that: `QuoteLineItem::deriveTotal()` says the same
     * arithmetic, and neither recomputes on read. An operator who writes
     * "3 × €95 = €280" has made an offer at €280, and a form that corrected them
     * would send the guest a number their operator did not type.
     */
    public static function prefillTotal(Get $get, Set $set): void
    {
        $qty = max(1, (int) $get('qty'));
        $unit = (float) $get('unit_price_cents');

        $set('total_cents', number_format($qty * $unit, 2, '.', ''));
    }

    /** Cents to euros, for a form field. */
    public static function toEuros(mixed $state): string
    {
        return number_format(((int) $state) / 100, 2, '.', '');
    }

    /**
     * Euros to cents, once.
     *
     * §1.4: every money column is an integer. `round()` rather than a cast,
     * because `(int) (0.29 * 100)` is 28 in binary floating point and an
     * operator would find out on an invoice.
     */
    public static function toCents(mixed $state): int
    {
        return (int) round(((float) $state) * 100);
    }

    /** @return Builder<Quote> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['booking', 'lineItems']);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuotes::route('/'),
            'edit' => Pages\EditQuote::route('/{record}/edit'),
        ];
    }
}
