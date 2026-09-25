<?php

declare(strict_types=1);

namespace App\Filament\App\Resources;

use App\Domain\Booking\Actions\CreateManualBooking;
use App\Domain\Booking\Data\BookingDraftData;
use App\Domain\Booking\Data\ManualBookingAdjustment;
use App\Enums\BookingMode;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\PaymentGatewayName;
use App\Exceptions\HoldRefused;
use App\Filament\App\Resources\BookingResource\Pages;
use App\Filament\Forms\MoneyInput;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Product;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Authorization\CrewWindow;
use App\Support\Format\MoneyFormatter;
use App\Support\Tenancy;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
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
    /** «Πληρωμή» on a phone booking (2026-09-25). */
    public const PAYMENT_PAID = 'paid';

    public const PAYMENT_DEPOSIT = 'deposit';

    public const PAYMENT_ON_THE_DAY = 'on_the_day';

    protected static ?string $model = Booking::class;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?int $navigationSort = 10;

    /**
     * Off the crew's menu (Mike, 2026-09-24): scan, today, the calendar. Crew
     * keep what TEN-8 gives them — a booking opened from a departure's list
     * still opens — only the list of every booking stops being a menu entry.
     */
    public static function shouldRegisterNavigation(): bool
    {
        $user = auth()->user();

        return parent::shouldRegisterNavigation()
            && ! ($user instanceof User && $user->isCrewOnly());
    }

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.sales');
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
                ->icon('heroicon-o-map')
                ->schema([
                    Select::make('product_id')
                        ->label(__('bookings.form.trip.product'))
                        ->options(fn (): array => Product::query()
                            ->orderBy('id')
                            ->pluck('title', 'id')
                            ->all())
                        ->searchable()
                        ->live()
                        // A charter's own start, filled in from the trip and
                        // left for the operator to change (2026-09-25).
                        ->afterStateUpdated(static function (Set $set, mixed $state): void {
                            $product = is_numeric($state) ? Product::query()->find((int) $state) : null;
                            $default = trim((string) $product?->default_start_time);

                            $set('start_time', $default !== '' ? substr($default, 0, 5) : null);
                        })
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
                        // A per-seat booking is always on a sailing: never
                        // today's first by default (2026-09-25).
                        ->required(fn (Get $get): bool => self::modeOf($get('product_id')) === BookingMode::PerSeat)
                        ->visible(fn (Get $get): bool => self::modeOf($get('product_id')) === BookingMode::PerSeat),

                    // A charter has no departures, so its day and hour are asked
                    // here, on the operator's calendar (2026-09-25). Until then
                    // every phone charter landed on today's UTC date.
                    DatePicker::make('date')
                        ->label(__('bookings.form.trip.date'))
                        ->timezone('UTC')
                        ->native(false)
                        ->default(static fn (): string => self::tenantToday())
                        ->required(fn (Get $get): bool => self::isCharter($get('product_id')))
                        ->visible(fn (Get $get): bool => self::isCharter($get('product_id'))),

                    TimePicker::make('start_time')
                        ->label(__('bookings.form.trip.start_time'))
                        ->timezone('UTC')
                        ->seconds(false)
                        ->native(false)
                        ->required(fn (Get $get): bool => self::isCharter($get('product_id')))
                        ->visible(fn (Get $get): bool => self::isCharter($get('product_id'))),

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
                ->icon('heroicon-o-user')
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
                ->icon('heroicon-o-currency-euro')
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
                ->icon('heroicon-o-banknotes')
                ->schema([
                    // «Πληρωμή» (Mike, 2026-09-25): paid in full, a deposit, or
                    // everything on the day. The last two confirm the booking
                    // with the rest open, so it keeps its seats and never
                    // expires.
                    Radio::make('payment')
                        ->label(__('bookings.form.settle.payment'))
                        ->options([
                            self::PAYMENT_PAID => __('bookings.form.settle.payment_paid'),
                            self::PAYMENT_DEPOSIT => __('bookings.form.settle.payment_deposit'),
                            self::PAYMENT_ON_THE_DAY => __('bookings.form.settle.payment_on_the_day'),
                        ])
                        ->required()
                        ->live()
                        ->columnSpanFull(),

                    Select::make('paid_by')
                        ->label(__('bookings.form.settle.method'))
                        ->options(self::manualMethods())
                        // BKG-33: neither of these calls anything, and both are
                        // excluded from gateway reconciliation because there is
                        // no gateway to reconcile against.
                        ->helperText(__('bookings.form.settle.help'))
                        ->required(fn (Get $get): bool => $get('payment') === self::PAYMENT_PAID)
                        ->visible(fn (Get $get): bool => $get('payment') === self::PAYMENT_PAID),

                    MoneyInput::make('deposit_amount', __('bookings.form.settle.deposit_amount'))
                        ->required(fn (Get $get): bool => $get('payment') === self::PAYMENT_DEPOSIT)
                        ->visible(fn (Get $get): bool => $get('payment') === self::PAYMENT_DEPOSIT),

                    Select::make('deposit_by')
                        ->label(__('bookings.form.settle.deposit_by'))
                        ->options(self::manualMethods())
                        ->required(fn (Get $get): bool => $get('payment') === self::PAYMENT_DEPOSIT)
                        ->visible(fn (Get $get): bool => $get('payment') === self::PAYMENT_DEPOSIT),

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

                // On a phone's box and on a desktop row, but not on a tablet
                // held upright, where eight columns cut the status off (phone
                // audit, 2026-09-23). `box-lists` hides the class at 768–1023.
                TextColumn::make('guest_name')
                    ->label(__('bookings.table.guest'))
                    ->searchable()
                    ->extraHeaderAttributes(['class' => 'ka-tablet-hidden'])
                    ->extraCellAttributes(['class' => 'ka-tablet-hidden']),

                TextColumn::make('product.title')
                    ->label(__('bookings.table.product'))
                    ->toggleable()
                    ->visibleFrom('md'),

                TextColumn::make('pax_total')
                    ->label(__('bookings.table.pax'))
                    ->visibleFrom('lg'),

                TextColumn::make('status')
                    ->label(__('bookings.table.status'))
                    // Label and colour from the enum (`HasLabel`, `HasColor`).
                    ->badge(),

                // BKG-34: *"flagged as imported in the panel"*. A badge rather
                // than a column of `widget` repeated four hundred times — the
                // interesting fact is that a booking is **not** ordinary.
                TextColumn::make('source')
                    ->label(__('bookings.table.source'))
                    // Label and colour from the enum (`HasLabel`, `HasColor`).
                    ->badge()
                    ->visibleFrom('lg'),

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

    /** The trip's booking mode, or null before one is picked. */
    private static function modeOf(mixed $productId): ?BookingMode
    {
        return is_numeric($productId) ? Product::query()->find((int) $productId)?->mode : null;
    }

    /** A trip sold whole (or on request), which has no departures to pick. */
    private static function isCharter(mixed $productId): bool
    {
        $mode = self::modeOf($productId);

        return $mode !== null && $mode !== BookingMode::PerSeat;
    }

    /** Today on the operator's calendar, not the server's. */
    private static function tenantToday(): string
    {
        return Carbon::now(Tenancy::current()->timezone ?? (string) config('kaiki.defaults.timezone'))->toDateString();
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

        // A departure that was picked and cannot be found (deleted, or not this
        // operator's) is refused, never quietly replaced by today's first sailing.
        if (isset($data['departure_id']) && ! $departure instanceof Departure) {
            throw HoldRefused::departureUnavailable();
        }

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
        $payment = isset($data['payment']) ? (string) $data['payment'] : null;

        return app(CreateManualBooking::class)(
            new BookingDraftData(
                product: $product,
                // A charter's day as the operator typed it; never the server's
                // UTC today (2026-09-25).
                date: $departure instanceof Departure
                    ? $departure->local_date->copy()
                    : Carbon::parse(isset($data['date']) && $data['date'] !== '' ? substr((string) $data['date'], 0, 10) : self::tenantToday()),
                guestName: (string) $data['guest_name'],
                guestEmail: (string) $data['guest_email'],
                guestPhone: isset($data['guest_phone']) ? (string) $data['guest_phone'] : null,
                locale: app()->getLocale(),
                paxByCode: $pax,
                // The picked departure's own time (2026-09-24): without it, a day
                // with two sailings of the same trip put the booking on the first.
                startTime: $departure instanceof Departure
                    ? (string) $departure->local_time
                    : (isset($data['start_time']) && $data['start_time'] !== '' ? substr((string) $data['start_time'], 0, 5) : null),
                // And the departure itself (2026-09-25), used as it is: a
                // cancelled or blocked sailing is refused, never swapped.
                departure: $departure,
                specialRequests: isset($data['special_requests']) ? (string) $data['special_requests'] : null,
            ),
            adjustment: $adjustment,
            capacityOverrideReason: ($data['override_capacity'] ?? false)
                ? (string) ($data['capacity_reason'] ?? '')
                : null,
            // A blank select is `null`, and `isset()` already excludes it — an
            // extra `!== null` reads as caution and is dead code. With no
            // «Πληρωμή» chosen (a caller other than the form), `paid_by` alone
            // decides, as it always has.
            paidBy: in_array($payment, [null, self::PAYMENT_PAID], true) && isset($data['paid_by'])
                ? PaymentGatewayName::from((string) $data['paid_by'])
                : null,
            depositCents: $payment === self::PAYMENT_DEPOSIT ? (int) ($data['deposit_amount'] ?? 0) : null,
            depositBy: $payment === self::PAYMENT_DEPOSIT && isset($data['deposit_by'])
                ? PaymentGatewayName::from((string) $data['deposit_by'])
                : null,
            payOnTheDay: $payment === self::PAYMENT_ON_THE_DAY,
        );
    }

    /**
     * Money that arrives by hand: cash, card on the POS, or a bank transfer.
     *
     * @return array<string, string>
     */
    private static function manualMethods(): array
    {
        return [
            PaymentGatewayName::Cash->value => PaymentGatewayName::Cash->label(),
            PaymentGatewayName::Pos->value => PaymentGatewayName::Pos->label(),
            PaymentGatewayName::BankTransfer->value => PaymentGatewayName::BankTransfer->label(),
        ];
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
