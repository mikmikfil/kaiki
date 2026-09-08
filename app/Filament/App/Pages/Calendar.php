<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Domain\Availability\Actions\CreateVesselBlock;
use App\Domain\Availability\Support\LocalDay;
use App\Domain\Availability\Support\Window;
use App\Domain\Availability\VesselCalendar;
use App\Domain\Operations\Support\CalendarDay;
use App\Enums\BlockReason;
use App\Enums\BookingStatus;
use App\Enums\DepartureStatus;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\User;
use App\Models\Vessel;
use App\Support\Authorization\Capability;
use App\Support\Authorization\CrewWindow;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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

    protected static ?int $navigationSort = 2;

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
        return __('panel.groups.operations');
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

    public function mount(): void
    {
        $this->date = Carbon::now($this->timezone())->toDateString();
    }

    public function today(): void
    {
        $this->date = Carbon::now($this->timezone())->toDateString();
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
                ]);
            });
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
