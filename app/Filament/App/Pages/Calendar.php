<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Domain\Availability\Actions\AssignDepartureCrew;
use App\Domain\Availability\Actions\CreateVesselBlock;
use App\Domain\Availability\Support\LocalDay;
use App\Domain\Availability\Support\Window;
use App\Domain\Availability\VesselCalendar;
use App\Domain\Booking\Actions\CreateManualBooking;
use App\Domain\Booking\Data\BookingDraftData;
use App\Domain\Operations\Support\CalendarDay;
use App\Domain\Pricing\Actions\ComputePrice;
use App\Enums\BlockReason;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\DepartureStatus;
use App\Enums\PaymentGatewayName;
use App\Enums\Role;
use App\Exceptions\HoldRefused;
use App\Filament\App\Resources\DepartureResource;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\User;
use App\Models\Vessel;
use App\Support\Authorization\Capability;
use App\Support\Authorization\CrewWindow;
use App\Support\Format\MoneyFormatter;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The fleet's day, as a timeline (spec OPS-3, OPS-4).
 *
 * One row per boat, one track per row, and every occupation on it: shared
 * departures, private blocks and the ones an external calendar pushed in.
 *
 * ## Everything is written through the actions that already exist
 *
 * A drag creates a block through {@see CreateVesselBlock} and never by writing
 * the row — that action is where AVL-4's all-day rule and the inverted-window
 * refusal live, and a second path to `vessel_blocks` is a second set of rules.
 * Occupancy is read through {@see VesselCalendar}, which ADR-0023 makes the only
 * class permitted to ask.
 *
 * ## A block over a booked departure is allowed, and warned about
 *
 * The tempting design refuses it. That would be wrong: a boat that has broken
 * down is blocked whether or not somebody has bought a seat on it, and refusing
 * the block would leave the operator with no way to say what has happened. So
 * the confirmation names what the block covers — *"this covers 2 departures with
 * 9 passengers booked"* — and lets them decide. The passengers are then the
 * weather-cancellation flow's problem (#121), which is the screen built for
 * exactly that conversation.
 */
class Calendar extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static string $view = 'filament.app.pages.calendar';

    protected static ?int $navigationSort = 3;

    /** The local date being shown, as `Y-m-d`. */
    public string $date = '';

    public static function getNavigationLabel(): string
    {
        return __('calendar.title');
    }

    public function getTitle(): string
    {
        return __('calendar.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.today');
    }

    /**
     * TEN-8: crew are read-only within a departure window and have no business
     * blocking a boat. `ViewDepartures` is the floor for seeing the calendar at
     * all; blocking asks for more, below.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasCapability(Capability::ViewDepartures);
    }

    /**
     * The date field beside the arrows (Mike, 2026-09-25: «να δω τι παίζει στις
     * 15 Οκτωβρίου»). Its own property rather than `date` itself, so a cleared
     * or mistyped field never becomes the day the page shows; `goTo` decides.
     */
    public ?string $jump = null;

    public function mount(): void
    {
        $this->date = Carbon::now($this->timezone())->toDateString();
        $this->jump = $this->date;
    }

    public function today(): void
    {
        $this->date = Carbon::now($this->timezone())->toDateString();
        $this->jump = $this->date;
    }

    /**
     * The picker. A day, never an instant: pinned to UTC like every date field
     * in the panel (see AppPanelProvider), shown as 15/10/2026, and for crew
     * only the days of their window are offered.
     */
    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('jump')
                ->label(__('calendar.jump'))
                ->hiddenLabel()
                ->native(false)
                ->displayFormat('d/m/Y')
                ->firstDayOfWeek(1)
                ->closeOnDateSelection()
                ->minDate(fn (): ?string => CrewWindow::applies() ? CrewWindow::firstDay()->toDateString() : null)
                ->maxDate(fn (): ?string => CrewWindow::applies() ? CrewWindow::lastDay()->toDateString() : null)
                ->live()
                ->afterStateUpdated(fn (?string $state) => $this->goTo($state)),
        ]);
    }

    /**
     * Show the day the operator picked — and, like the arrows, not a day
     * outside the crew window, nor anything that is not a date. Either way the
     * field goes back to the day being shown.
     */
    public function goTo(?string $value): void
    {
        $value = substr(trim((string) $value), 0, 10);
        $picked = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            ? CarbonImmutable::createFromFormat('!Y-m-d', $value, $this->timezone())
            : null;

        if ($picked instanceof CarbonImmutable
            && $picked->toDateString() === $value
            && (! CrewWindow::applies() || CrewWindow::covers($picked))) {
            $this->date = $value;
        }

        $this->jump = $this->date;
    }

    /**
     * Move a day, and stop at the edge of the crew window.
     *
     * `canAccess` gates the screen and nothing gated the *dates*, so a crew
     * member could page this calendar back through the whole season — each day
     * carrying a pax list — while the departures list beside it correctly showed
     * them today and tomorrow. Clamped rather than refused: an arrow that does
     * nothing at the edge is how every date control behaves, and an error
     * message would suggest they had done something wrong.
     */
    public function shiftDays(int $days): void
    {
        $moved = CarbonImmutable::parse($this->date, $this->timezone())->addDays($days);

        if (CrewWindow::applies() && ! CrewWindow::covers($moved)) {
            return;
        }

        $this->date = $moved->toDateString();
        $this->jump = $this->date;
    }

    public function getDay(): CalendarDay
    {
        return CalendarDay::for($this->date, $this->timezone());
    }

    /** The heading above the track: the date as an operator reads it. */
    public function getHeading(): string
    {
        return Carbon::parse($this->date, $this->timezone())
            ->locale(app()->getLocale())
            ->isoFormat('dddd D MMMM YYYY');
    }

    /**
     * Each departure's status on the day, keyed by uuid, for the phone's list.
     *
     * The timeline says "cancelled" with a strike and nothing more, which is
     * all a bar has room for. A row on a phone has room for the word, so it
     * asks for the status here — one query for the day, not one per row —
     * rather than widening `CalendarDay`, whose bars every other caller shares.
     *
     * @return array<string, DepartureStatus>
     */
    public function departureStatuses(CalendarDay $day): array
    {
        $uuids = [];

        foreach ($day->rows as $row) {
            foreach ($row['bars'] as $bar) {
                if ($bar['kind'] === 'departure') {
                    $uuids[] = (string) $bar['uuid'];
                }
            }
        }

        if ($uuids === []) {
            return [];
        }

        $statuses = [];

        foreach (Departure::query()->whereIn('uuid', $uuids)->get(['uuid', 'status']) as $departure) {
            $statuses[(string) $departure->uuid] = $departure->status;
        }

        return $statuses;
    }

    /** Whether a departure opens its passenger list — the same test as `paxAction`. */
    public function canOpenPax(): bool
    {
        return self::userCan(Capability::ViewPaxList);
    }

    /**
     * Block a boat for a window the operator dragged out.
     *
     * The form is pre-filled from the drag and still editable: a drag on a track
     * two hundred pixels wide is accurate to about ten minutes, and an operator
     * who meant 09:00 should not have to drag again to get it.
     */
    public function blockAction(): Action
    {
        return Action::make('block')
            ->label(__('calendar.block.title'))
            ->modalHeading(__('calendar.block.title'))
            ->visible(fn (): bool => self::userCan(Capability::ManageBookings))
            // What the drag measured — or, from the phone's «Δέσμευση σκάφους»
            // on a boat's card, only the boat. The drag always sent its times;
            // until 2026-09-24 nothing put them into the form.
            ->fillForm(fn (array $arguments): array => [
                'vessel' => $arguments['vessel'] ?? null,
                'starts_at' => $arguments['starts_at'] ?? null,
                'ends_at' => $arguments['ends_at'] ?? null,
                'reason' => BlockReason::Manual->value,
            ])
            ->form([
                Select::make('vessel')
                    ->label(__('calendar.block.vessel'))
                    ->options(fn (): array => Vessel::query()
                        ->orderBy('sort_order')
                        ->pluck('name', 'uuid')
                        ->all())
                    ->required(),
                TextInput::make('starts_at')
                    ->label(__('calendar.block.starts_at'))
                    ->type('time')
                    ->required(),
                TextInput::make('ends_at')
                    ->label(__('calendar.block.ends_at'))
                    ->type('time')
                    ->required(),
                Select::make('reason')
                    ->label(__('calendar.block.reason'))
                    ->options([
                        BlockReason::Maintenance->value => BlockReason::Maintenance->label(),
                        BlockReason::PrivateBooking->value => BlockReason::PrivateBooking->label(),
                        BlockReason::Manual->value => BlockReason::Manual->label(),
                    ])
                    ->default(BlockReason::Manual->value)
                    ->required(),
                TextInput::make('title')->label(__('calendar.block.label'))->maxLength(120),
                Textarea::make('notes')->label(__('calendar.block.notes'))->rows(2),
            ])
            ->action(function (array $data): void {
                $vessel = Vessel::query()->where('uuid', $data['vessel'])->firstOrFail();

                $covered = $this->covers($vessel, $data['starts_at'], $data['ends_at']);

                // `start_time` / `end_time`, which is what the action's own
                // contract calls them. The form fields are named for the
                // operator; the translation happens here, once.
                (new CreateVesselBlock)($vessel, [
                    'local_date' => $this->date,
                    'start_time' => $data['starts_at'],
                    'end_time' => $data['ends_at'],
                    'reason' => $data['reason'],
                    'title' => $data['title'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'created_by_user_id' => auth()->id(),
                ]);

                $notification = Notification::make()->title(__('calendar.block.created'));

                if ($covered['departures'] > 0) {
                    // Named rather than refused. A boat that has broken down is
                    // blocked whether or not somebody has bought a seat, and the
                    // operator needs to know which sailings they have just
                    // covered so they can go and cancel them properly.
                    $notification
                        ->warning()
                        ->body(__('calendar.block.covers', [
                            'departures' => $covered['departures'],
                            'pax' => $covered['pax'],
                        ]));
                } else {
                    $notification->success();
                }

                $notification->send();
            });
    }

    /**
     * «Ανάθεση»: captain and crew from the phone's day list, without opening the
     * departure (Mike, 2026-09-24: owner and manager only). Written through
     * {@see AssignDepartureCrew}, so the overlap refusal and the emails are the
     * departure page's own.
     */
    public function assignAction(): Action
    {
        return Action::make('assign')
            ->label(__('calendar.assign.title'))
            ->modalHeading(__('calendar.assign.title'))
            ->visible(fn (): bool => self::canAssign())
            ->fillForm(function (array $arguments): array {
                $departure = Departure::query()->where('uuid', $arguments['departure'] ?? '')->first();

                return [
                    'captain_user_id' => $departure?->captain_user_id,
                    'captain_name' => $departure?->captain_name,
                    'crew_user_ids' => $departure === null ? [] : ($departure->crew_user_ids ?? []),
                    'crew_names' => $departure === null ? [] : ($departure->crew_names ?? []),
                ];
            })
            ->form([
                Select::make('captain_user_id')
                    ->label(__('availability.departure.crew.captain.label'))
                    ->options(static fn (): array => DepartureResource::peopleOptions(captainsOnly: true))
                    ->searchable()
                    ->live(),
                TextInput::make('captain_name')
                    ->label(__('availability.departure.crew.captain_name.label'))
                    ->maxLength(120)
                    ->visible(static fn (Get $get): bool => blank($get('captain_user_id'))),
                Select::make('crew_user_ids')
                    ->label(__('availability.departure.crew.members.label'))
                    ->options(static fn (): array => DepartureResource::peopleOptions())
                    ->multiple()
                    ->searchable(),
                DepartureResource::crewNamesField(),
            ])
            ->action(function (array $data, array $arguments): void {
                $departure = Departure::query()->where('uuid', $arguments['departure'] ?? '')->firstOrFail();

                app(AssignDepartureCrew::class)(
                    $departure,
                    $data['captain_user_id'] ?? null,
                    $data['captain_name'] ?? null,
                    $data['crew_user_ids'] ?? [],
                    (array) ($data['crew_names'] ?? []),
                );

                Notification::make()->title(__('calendar.assign.saved'))->success()->send();
            });
    }

    public static function canAssign(): bool
    {
        $user = auth()->user();

        return $user instanceof User && ($user->hasRole(Role::Owner) || $user->hasRole(Role::Manager));
    }

    /**
     * The passenger list behind a departure (OPS-3), gated on `ViewPaxList`.
     *
     * Names and party sizes only. Document numbers are a manifest (OPS-10) and
     * a separate, logged action — a list an operator opens forty times a day is
     * not where the most sensitive field in the database belongs.
     */
    public function paxAction(): Action
    {
        return Action::make('pax')
            ->label(__('calendar.pax.title'))
            ->modalHeading(__('calendar.pax.title'))
            ->visible(fn (): bool => self::userCan(Capability::ViewPaxList))
            ->modalSubmitAction(false)
            ->modalContent(function (array $arguments) {
                $departure = Departure::query()
                    ->where('uuid', $arguments['departure'] ?? '')
                    ->with('product')
                    ->first();

                // Only the bookings that are actually coming. A cancelled one on
                // the list is a person the crew would count and wait for.
                $bookings = $departure === null
                    ? collect()
                    : Booking::query()
                        ->where('departure_id', $departure->getKey())
                        ->whereIn('status', [
                            BookingStatus::Confirmed->value,
                            BookingStatus::CheckedIn->value,
                            BookingStatus::Completed->value,
                        ])
                        ->orderBy('guest_name')
                        ->get();

                return view('filament.app.pages.calendar-pax', [
                    'departure' => $departure,
                    'bookings' => $bookings,
                    'canSell' => self::userCan(Capability::SellOnQuay),
                ]);
            });
    }

    /**
     * «Πώληση τώρα» on the quay (Mike, 2026-09-24, option Β).
     *
     * A seat on this departure, sold and paid on the spot: how many of each age
     * band, the price that comes to, the guest's name (email and phone only if
     * they give them), then «Κάρτα στο POS μου» or «Μετρητά». The guest pays on
     * the operator's own terminal or in cash; Kaiki records the booking as
     * confirmed and paid, issues no receipt (their cash register does) and
     * sends no text message.
     *
     * Every role may sell ({@see Capability::SellOnQuay}), crew included, and
     * within their window only. The booking goes through
     * {@see CreateManualBooking}, so the price is the website's and the seats
     * are held under the same lock; a full boat is refused in words.
     */
    public function sellAction(): Action
    {
        return Action::make('sell')
            ->label(__('calendar.sell.title'))
            ->modalHeading(__('calendar.sell.title'))
            ->modalWidth('md')
            ->visible(fn (): bool => self::userCan(Capability::SellOnQuay))
            ->fillForm(function (array $arguments): array {
                $departure = $this->sellable($arguments);
                $bands = $departure?->product->ageBands ?? collect();
                $fill = ['guest_name' => null, 'guest_email' => null, 'guest_phone' => null];

                foreach ($bands->values() as $i => $band) {
                    $fill['pax_' . $band->code] = $i === 0 ? 1 : 0;
                }

                return $fill;
            })
            ->form(function (array $arguments): array {
                $departure = $this->sellable($arguments);

                if (! $departure instanceof Departure) {
                    return [Placeholder::make('gone')->hiddenLabel()->content(__('calendar.sell.unavailable'))];
                }

                $fields = [
                    Placeholder::make('which')
                        ->hiddenLabel()
                        ->content(fn (): string => __('calendar.sell.which', [
                            'trip' => (string) $departure->product?->title,
                            'time' => substr((string) $departure->local_time, 0, 5),
                            'left' => $departure->seatsAvailable(),
                        ])),
                ];

                foreach ($departure->product->ageBands as $band) {
                    $fields[] = TextInput::make('pax_' . $band->code)
                        ->label((string) $band->label)
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->maxValue(max(0, $departure->seatsAvailable()))
                        ->default(0)
                        ->live(debounce: 300);
                }

                $fields[] = Placeholder::make('total')
                    ->label(__('calendar.sell.total'))
                    ->content(function (Get $get) use ($departure): string {
                        $cents = $this->priceFor($departure, $this->paxFrom($departure, $get));

                        return $cents === null
                            ? __('calendar.sell.no_price')
                            : MoneyFormatter::format($cents, app()->getLocale(), MoneyFormatter::currency());
                    });

                $fields[] = TextInput::make('guest_name')->label(__('calendar.sell.name'))->required()->maxLength(120);
                $fields[] = TextInput::make('guest_email')->label(__('calendar.sell.email'))->email()->maxLength(190);
                $fields[] = TextInput::make('guest_phone')->label(__('calendar.sell.phone'))->tel()->maxLength(32);

                return $fields;
            })
            // Two ways to have been paid, one button each, and no third
            // «Υποβολή» that would not know which.
            ->modalSubmitAction(false)
            ->extraModalFooterActions(fn (Action $action): array => [
                $action->makeModalSubmitAction('pos', arguments: ['paid_by' => PaymentGatewayName::Pos->value])
                    ->label(__('calendar.sell.pos')),
                $action->makeModalSubmitAction('cash', arguments: ['paid_by' => PaymentGatewayName::Cash->value])
                    ->label(__('calendar.sell.cash'))
                    ->color('gray'),
            ])
            ->action(function (array $data, array $arguments, Action $action): void {
                $departure = $this->sellable($arguments);
                $paidBy = PaymentGatewayName::tryFrom((string) ($arguments['paid_by'] ?? ''));

                if (! $departure instanceof Departure || ! in_array($paidBy, [PaymentGatewayName::Pos, PaymentGatewayName::Cash], true)) {
                    Notification::make()->title(__('calendar.sell.unavailable'))->danger()->send();
                    $action->halt();

                    return;
                }

                $pax = [];

                foreach ($departure->product->ageBands as $band) {
                    $qty = (int) ($data['pax_' . $band->code] ?? 0);

                    if ($qty > 0) {
                        $pax[$band->code] = $qty;
                    }
                }

                if ($pax === []) {
                    Notification::make()->title(__('calendar.sell.no_pax'))->warning()->send();
                    $action->halt();

                    return;
                }

                try {
                    $booking = app(CreateManualBooking::class)(
                        new BookingDraftData(
                            product: $departure->product,
                            date: Carbon::parse($departure->local_date->toDateString()),
                            guestName: trim((string) $data['guest_name']),
                            guestEmail: filled($data['guest_email'] ?? null) ? trim((string) $data['guest_email']) : null,
                            guestPhone: filled($data['guest_phone'] ?? null) ? trim((string) $data['guest_phone']) : null,
                            locale: app()->getLocale(),
                            paxByCode: $pax,
                            // This departure, at its own time: a day with two
                            // sailings of the trip must not sell the other one.
                            startTime: (string) $departure->local_time,
                        ),
                        paidBy: $paidBy,
                        source: BookingSource::Quay,
                    );
                } catch (HoldRefused) {
                    Notification::make()->title(__('calendar.sell.full'))->danger()->send();
                    $action->halt();

                    return;
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title(__('calendar.sell.refused'))
                        ->body(collect($exception->errors())->flatten()->first())
                        ->danger()
                        ->send();
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title(__('calendar.sell.done', [
                        'pax' => array_sum($pax),
                        'amount' => MoneyFormatter::format((int) $booking->total_cents, app()->getLocale(), MoneyFormatter::currency()),
                    ]))
                    ->body(__('calendar.sell.done_body', ['how' => $paidBy->label(), 'reference' => (string) $booking->reference]))
                    ->success()
                    ->send();
            });
    }

    /**
     * The departure a sale is for, if it may be sold now: this tenant's, not
     * cancelled or closed, and — for crew — inside their window.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function sellable(array $arguments): ?Departure
    {
        $departure = Departure::query()
            ->where('uuid', (string) ($arguments['departure'] ?? ''))
            ->with(['product.ageBands' => static fn ($query) => $query->orderBy('sort_order')])
            ->first();

        if (! $departure instanceof Departure || $departure->product === null || ! $departure->status->isSellable()) {
            return null;
        }

        if (CrewWindow::applies() && ! CrewWindow::covers(CarbonImmutable::parse($departure->local_date->toDateString(), $this->timezone()))) {
            return null;
        }

        return $departure;
    }

    /** @return array<string, int> */
    private function paxFrom(Departure $departure, Get $get): array
    {
        $pax = [];

        foreach ($departure->product->ageBands as $band) {
            $qty = (int) $get('pax_' . $band->code);

            if ($qty > 0) {
                $pax[$band->code] = $qty;
            }
        }

        return $pax;
    }

    /**
     * What the party comes to, from the same {@see ComputePrice} the website
     * asks; null when there is nobody yet or the trip cannot be priced that day.
     *
     * @param  array<string, int>  $pax
     */
    private function priceFor(Departure $departure, array $pax): ?int
    {
        if ($pax === [] || $departure->product === null) {
            return null;
        }

        try {
            return app(ComputePrice::class)($departure->product, Carbon::parse($departure->local_date->toDateString()), $pax)->totalCents;
        } catch (ValidationException) {
            return null;
        }
    }

    /**
     * What a proposed block would cover.
     *
     * Read through {@see VesselCalendar::conflictingDepartures()} rather than a
     * query here, for ADR-0023's reason — and `conflictingDepartures` rather
     * than `occupationsFor` because an empty departure is still a sailing the
     * operator has published, and covering one silently is the same surprise.
     *
     * @return array{departures: int, pax: int}
     */
    private function covers(Vessel $vessel, string $startsAt, string $endsAt): array
    {
        $day = LocalDay::of($this->date, $this->timezone());

        $window = Window::of(
            Carbon::parse("{$this->date} {$startsAt}", $this->timezone())->utc(),
            Carbon::parse("{$this->date} {$endsAt}", $this->timezone())->utc(),
        );

        unset($day);

        /** @var Collection<int, Departure> $found */
        $found = VesselCalendar::conflictingDepartures($vessel, $window)
            ->reject(static fn (Departure $departure): bool => $departure->status === DepartureStatus::Cancelled);

        return [
            'departures' => $found->count(),
            'pax' => (int) $found->sum('seats_sold'),
        ];
    }

    /**
     * One capability check, in one place.
     *
     * Two actions on this page ask, and a third will. Written out at each call
     * site it is three chances to check the wrong capability or to forget the
     * `instanceof` — which fails open when nobody is signed in.
     */
    private static function userCan(Capability $capability): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasCapability($capability);
    }

    private function timezone(): string
    {
        return Tenancy::current()?->timezone ?: config('app.timezone', 'UTC');
    }
}
