<?php

declare(strict_types=1);

namespace App\Filament\App\Resources;

use App\Domain\Booking\Actions\CreateManualBooking;
use App\Domain\Booking\Data\BookingDraftData;
use App\Domain\Booking\Data\ManualBookingAdjustment;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\PaymentGatewayName;
use App\Filament\App\Resources\BookingResource\Pages;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Product;
use App\Support\Authorization\Capability;
use App\Support\Authorization\CrewWindow;
use App\Support\Format\MoneyFormatter;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Bookings in `/app`, and the manual one an operator takes on the phone
 * (spec BKG-30 to BKG-33, TEN-8).
 *
 * ## Thin (CNV-5), and the create form is the only screen that writes
 *
 * {@see CreateManualBooking} does the work: it runs the same pricing engine the
 * website runs, applies BKG-31's adjustment into the price snapshot, carries
 * BKG-32's capacity override with its audit row, and marks the booking paid by
 * cash or bank. A form that assembled a `Booking` itself would be a second
 * insert path, drifting from the first in exactly the fields that matter.
 *
 * ## Everything else here is read-only, on purpose
 *
 * A confirmed booking is not an editable record. Changing its party size, its
 * date or its total in a form would move seats, invalidate a price snapshot and
 * contradict a policy snapshot the guest was shown — each of which has an
 * Action that does it properly. Until those Actions have panel surfaces (M3 and
 * M5), the honest thing is a list and a view rather than a form that half
 * works.
 *
 * ## What crew can see here
 *
 * TEN-8 gives them `ViewPaxList`, so they reach the list and the view. The
 * money columns are gated on `ViewFinancials`, which they do not hold — and
 * they are **absent** from a crew member's table rather than blanked, because a
 * column of dashes still tells them a number exists.
 */
class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('bookings.nav');
    }

    public static function getModelLabel(): string
    {
        return __('bookings.model.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('bookings.model.plural');
    }

    /**
     * BKG-30's form: a booking taken by phone or at a desk.
     *
     * Product first, then a departure filtered to that product. The order is
     * the operator's own — somebody on the phone is asked "which trip" before
     * "which day" — and a departure select that listed every sailing in the
     * fleet would be unusable by the second week of a season.
     */
    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make(__('bookings.form.trip.heading'))
                ->schema([
                    Select::make('product_id')
                        ->label(__('bookings.form.trip.product'))
                        ->options(fn (): array => Product::query()
                            ->orderBy('id')
                            ->pluck('title', 'id')
                            ->all())
                        ->searchable()
                        ->live()
                        ->required(),

                    Select::make('departure_id')
                        ->label(__('bookings.form.trip.departure'))
                        // BKG-32: **every** departure of the product, including
                        // ones the public calendar hides for lead time or
                        // advance rules. That is the whole of the "may exceed"
                        // half of the requirement — a walk-in an hour before
                        // sailing is the case it exists for.
                        ->options(fn (Get $get): array => Departure::query()
                            ->where('product_id', (int) $get('product_id'))
                            ->orderBy('starts_at_utc')
                            ->get()
                            ->mapWithKeys(static fn (Departure $departure): array => [
                                $departure->getKey() => $departure->local_date->toDateString() . ' ' . substr((string) $departure->local_time, 0, 5),
                            ])
                            ->all())
                        ->searchable()
                        ->helperText(__('bookings.form.trip.departure_help'))
                        ->visible(fn (Get $get): bool => $get('product_id') !== null),

                    Repeater::make('pax')
                        ->label(__('bookings.form.trip.pax'))
                        ->schema([
                            Select::make('code')
                                ->label(__('bookings.form.trip.band'))
                                ->options(fn (Get $get): array => self::bandOptions($get('../../product_id')))
                                ->required(),
                            TextInput::make('qty')
                                ->label(__('bookings.form.trip.qty'))
                                ->numeric()
                                ->minValue(1)
                                ->default(1)
                                ->required(),
                        ])
                        ->columns(2)
                        ->defaultItems(1)
                        ->visible(fn (Get $get): bool => $get('product_id') !== null),
                ])
                ->columns(2),

            Section::make(__('bookings.form.guest.heading'))
                ->schema([
                    TextInput::make('guest_name')
                        ->label(__('bookings.form.guest.name'))
                        ->maxLength(120)
                        ->required(),
                    TextInput::make('guest_email')
                        ->label(__('bookings.form.guest.email'))
                        ->email()
                        ->maxLength(190)
                        ->required(),
                    TextInput::make('guest_phone')
                        ->label(__('bookings.form.guest.phone'))
                        ->tel()
                        ->maxLength(32),
                    Textarea::make('special_requests')
                        ->label(__('bookings.form.guest.special_requests'))
                        ->rows(2)
                        ->maxLength(2000)
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Section::make(__('bookings.form.price.heading'))
                ->description(__('bookings.form.price.help'))
                ->schema([
                    TextInput::make('discount_cents')
                        ->label(__('bookings.form.price.discount'))
                        ->numeric()
                        ->minValue(0)
                        ->suffix('¢')
                        ->helperText(__('bookings.form.price.discount_help')),

                    TextInput::make('override_total_cents')
                        ->label(__('bookings.form.price.override'))
                        ->numeric()
                        ->minValue(0)
                        ->suffix('¢')
                        ->helperText(__('bookings.form.price.override_help')),

                    Textarea::make('adjustment_reason')
                        ->label(__('bookings.form.price.reason'))
                        ->rows(2)
                        ->maxLength(500)
                        // BKG-31: *"with a reason"*. `ManualBookingAdjustment`
                        // refuses to be constructed without one, so this is the
                        // friendly half of a rule that is enforced anyway.
                        ->requiredWith('discount_cents,override_total_cents')
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->visible(fn (): bool => auth()->user()?->hasCapability(Capability::ManagePricing) ?? false),

            Section::make(__('bookings.form.settle.heading'))
                ->schema([
                    Select::make('paid_by')
                        ->label(__('bookings.form.settle.method'))
                        ->options([
                            PaymentGatewayName::Cash->value => PaymentGatewayName::Cash->label(),
                            PaymentGatewayName::BankTransfer->value => PaymentGatewayName::BankTransfer->label(),
                        ])
                        // BKG-33: neither of these calls anything, and both are
                        // excluded from gateway reconciliation because there is
                        // no gateway to reconcile against.
                        ->helperText(__('bookings.form.settle.help')),

                    Toggle::make('override_capacity')
                        ->label(__('bookings.form.settle.override_capacity'))
                        ->helperText(__('bookings.form.settle.override_capacity_help'))
                        ->live(),

                    Textarea::make('capacity_reason')
                        ->label(__('bookings.form.settle.capacity_reason'))
                        ->rows(2)
                        ->maxLength(500)
                        // BKG-32's *"explicit confirmation"*, which is a reason
                        // rather than a checkbox: a tick records that somebody
                        // clicked, and the reason records what they knew.
                        ->required(fn (Get $get): bool => (bool) $get('override_capacity'))
                        ->visible(fn (Get $get): bool => (bool) $get('override_capacity'))
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label(__('bookings.table.reference'))
                    ->searchable()
                    ->copyable(),

                TextColumn::make('local_date')
                    ->label(__('bookings.table.date'))
                    ->date()
                    ->sortable(),

                TextColumn::make('guest_name')
                    ->label(__('bookings.table.guest'))
                    ->searchable(),

                TextColumn::make('product.title')
                    ->label(__('bookings.table.product'))
                    ->toggleable(),

                TextColumn::make('pax_total')
                    ->label(__('bookings.table.pax')),

                TextColumn::make('status')
                    ->label(__('bookings.table.status'))
                    ->badge()
                    ->formatStateUsing(static fn (BookingStatus $state): string => $state->label()),

                // BKG-34: *"flagged as imported in the panel"*. A badge rather
                // than a column of `widget` repeated four hundred times — the
                // interesting fact is that a booking is **not** ordinary.
                TextColumn::make('source')
                    ->label(__('bookings.table.source'))
                    ->badge()
                    ->color(static fn (BookingSource $state): string => match ($state) {
                        BookingSource::Import => 'warning',
                        BookingSource::Manual => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(static fn (BookingSource $state): string => $state->label()),

                TextColumn::make('total_cents')
                    ->label(__('bookings.table.total'))
                    ->formatStateUsing(static fn (int $state): string => MoneyFormatter::format($state, app()->getLocale(), MoneyFormatter::currency()))
                    // TEN-8: absent for crew rather than blanked. A column of
                    // dashes still tells them a number exists.
                    ->visible(fn (): bool => auth()->user()?->hasCapability(Capability::ViewFinancials) ?? false)
                    ->alignEnd(),
            ])
            ->defaultSort('local_date', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('bookings.table.status'))
                    ->options(BookingStatus::options()),

                SelectFilter::make('source')
                    ->label(__('bookings.table.source'))
                    ->options(BookingSource::options()),
            ]);
    }

    /**
     * Take the booking, through the Action that knows how.
     *
     * Called by the create page rather than by a closure in the form, so the
     * translation from "what the form collected" to "what the engine needs"
     * exists once and is testable without a browser.
     *
     * @param  array<string, mixed>  $data
     */
    public static function createFromForm(array $data): Booking
    {
        $product = Product::query()->with('ageBands')->findOrFail((int) $data['product_id']);

        $departure = isset($data['departure_id'])
            ? Departure::query()->find((int) $data['departure_id'])
            : null;

        $pax = [];

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $data['pax'] ?? [];

        foreach ($rows as $row) {
            $code = (string) ($row['code'] ?? '');
            $qty = (int) ($row['qty'] ?? 0);

            if ($code !== '' && $qty > 0) {
                $pax[$code] = ($pax[$code] ?? 0) + $qty;
            }
        }

        $adjustment = self::adjustmentFrom($data);

        return app(CreateManualBooking::class)(
            new BookingDraftData(
                product: $product,
                date: $departure instanceof Departure ? $departure->local_date->copy() : Carbon::today(),
                guestName: (string) $data['guest_name'],
                guestEmail: (string) $data['guest_email'],
                guestPhone: isset($data['guest_phone']) ? (string) $data['guest_phone'] : null,
                locale: app()->getLocale(),
                paxByCode: $pax,
                specialRequests: isset($data['special_requests']) ? (string) $data['special_requests'] : null,
            ),
            adjustment: $adjustment,
            capacityOverrideReason: ($data['override_capacity'] ?? false)
                ? (string) ($data['capacity_reason'] ?? '')
                : null,
            // A blank select is `null`, and `isset()` already excludes it — an
            // extra `!== null` reads as caution and is dead code.
            paidBy: isset($data['paid_by'])
                ? PaymentGatewayName::from((string) $data['paid_by'])
                : null,
        );
    }

    /** @param array<string, mixed> $data */
    private static function adjustmentFrom(array $data): ?ManualBookingAdjustment
    {
        $discount = (int) ($data['discount_cents'] ?? 0);
        $override = $data['override_total_cents'] ?? null;

        if ($discount === 0 && $override === null) {
            return null;
        }

        return new ManualBookingAdjustment(
            reason: (string) ($data['adjustment_reason'] ?? ''),
            discountCents: $discount,
            overrideTotalCents: $override === null ? null : (int) $override,
        );
    }

    /** @return array<string, string> */
    private static function bandOptions(mixed $productId): array
    {
        if (! is_numeric($productId)) {
            return [];
        }

        $product = Product::query()->with('ageBands')->find((int) $productId);

        if (! $product instanceof Product) {
            return [];
        }

        return $product->ageBands
            ->mapWithKeys(static fn ($band): array => [$band->code => $band->label])
            ->all();
    }

    /** @return Builder<Booking> */
    /**
     * The row set, narrowed to the crew window for crew (TEN-8).
     *
     * `ViewPaxList` is what opens this screen, and crew hold it — so before
     * {@see CrewWindow} a skipper could read every booking the operator had
     * ever taken: each one's name, email address and telephone number. TEN-8
     * gives them "departures within a configurable window... the pax list", and
     * the pax list of a departure they cannot see is not one of the two.
     *
     * @return Builder<Booking>
     */
    public static function getEloquentQuery(): Builder
    {
        return CrewWindow::scopeBookings(parent::getEloquentQuery()->with(['product']));
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBookings::route('/'),
            'create' => Pages\CreateBooking::route('/create'),
            'view' => Pages\ViewBooking::route('/{record}'),
        ];
    }
}
