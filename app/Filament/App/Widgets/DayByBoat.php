<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets;

use App\Domain\Operations\Support\AttentionItems;
use App\Domain\Operations\Support\CalendarDay;
use App\Domain\Operations\Support\FirstSteps;
use App\Domain\Operations\Support\TodayHome;
use App\Filament\App\Pages\Calendar;
use App\Filament\App\Pages\CheckIn;
use App\Filament\App\Resources\BookingResource;
use App\Filament\App\Resources\DepartureResource;
use App\Support\Tenancy;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

/**
 * The home page: the day, by boat (product owner, 2026-09-16/17, «version 3»).
 *
 * Three parts, in the order an operator's morning asks for them:
 *
 * 1. **The next departure**, in the panel's deep blue: when, which trip, which
 *    boat and quay, how many are aboard, and the boarding button under the
 *    thumb.
 * 2. **Four boxes**, one question each: Αναχωρήσεις today, new Κρατήσεις,
 *    the Ημερολόγιο, and Προσοχή — the to-do items listed further down the page.
 *    Each box says its one number in words.
 * 3. **Today by boat.** On a phone, one box per boat: a small bar of when it
 *    is out, and its sailings underneath, each opening that departure. From a
 *    tablet up, the same day as lanes on a timeline.
 *
 * Every position on the bars and lanes comes from {@see CalendarDay}, the
 * class the calendar page uses, for the reason `TodayAtSea` gives: one answer
 * to "where is this bar", including on the day the clocks change.
 *
 * A box or button the person may not open is left out rather than shown and
 * refused: crew see departures and boarding, not bookings they cannot list.
 */
class DayByBoat extends Widget
{
    protected static string $view = 'filament.app.widgets.day-by-boat';

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    /** Aboard counts move while people board; a minute keeps them honest. */
    protected static ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        if (! Tenancy::check()) {
            return false;
        }

        return ! FirstSteps::applies();
    }

    /** @return array<string, mixed>|null */
    public function getNext(): ?array
    {
        $next = $this->home()->nextDeparture();

        if ($next === null) {
            return null;
        }

        $departure = $next['departure'];
        $now = Carbon::now($this->timezone());
        $minutes = (int) floor($now->diffInMinutes($next['starts'], false));

        $when = match (true) {
            $next['underway'] => __('dashboard.home.next.underway'),
            ! $next['today'] => $next['starts']->translatedFormat('l j F'),
            $minutes < 60 => trans_choice('dashboard.home.next.in_minutes', max($minutes, 1), ['count' => max($minutes, 1)]),
            default => trans_choice('dashboard.home.next.in_hours', intdiv($minutes, 60), ['count' => intdiv($minutes, 60)]),
        };

        // With boarding switched off for this operator, there is no "aboard" to
        // count: the box says how full the sailing is instead.
        $boards = Tenancy::current()?->usesCheckIn() === true;

        return [
            'when' => $when,
            'boards' => $boards,
            'booked' => (int) $departure->seats_sold,
            'capacity' => (int) $departure->capacity,
            'time' => $next['starts']->format('H:i'),
            'trip' => (string) $departure->product?->title,
            'where' => collect([$departure->vessel?->name, $departure->product?->meetingPoint?->name])->filter()->implode(' · '),
            'aboard' => $next['aboard'],
            'expected' => $next['expected'],
            'percent' => $next['expected'] > 0 ? (int) round($next['aboard'] / $next['expected'] * 100) : 0,
            'url' => DepartureResource::canViewAny() ? DepartureResource::getUrl('edit', ['record' => $departure]) : null,
        ];
    }

    /**
     * The boarding button, or null for somebody who may not board people.
     *
     * Follows the operator's two switches exactly as the boarding page does:
     * boarding off, no button at all; boarding on but QR off, the passenger
     * list and never «Σάρωση».
     *
     * @return array{url: string, label: string, icon: string}|null
     */
    public function getBoarding(): ?array
    {
        $tenant = Tenancy::current();

        if (! CheckIn::canAccess() || $tenant === null || ! $tenant->usesCheckIn()) {
            return null;
        }

        $qr = CheckIn::qrEnabled();

        return [
            'url' => CheckIn::getUrl(),
            'label' => $qr ? __('dashboard.home.next.scan') : __('dashboard.home.next.board'),
            'icon' => $qr ? 'heroicon-o-qr-code' : 'heroicon-o-list-bullet',
        ];
    }

    /** @return list<array{label: string, detail: string, url: string, icon: string, count: int|null, alert: bool}> */
    public function getBoxes(): array
    {
        $home = $this->home();
        $boxes = [];

        if (DepartureResource::canViewAny()) {
            $today = $home->departuresToday();

            $boxes[] = [
                'label' => __('dashboard.home.boxes.departures'),
                'detail' => trans_choice('dashboard.home.boxes.departures_today', $today, ['count' => $today]),
                'url' => DepartureResource::getUrl('index'),
                'icon' => 'heroicon-o-flag',
                'count' => null,
                'alert' => false,
            ];
        }

        if (BookingResource::canViewAny()) {
            $new = $home->newBookings();

            $boxes[] = [
                'label' => __('dashboard.home.boxes.bookings'),
                'detail' => trans_choice('dashboard.home.boxes.bookings_new', $new, ['count' => $new]),
                'url' => BookingResource::getUrl('index'),
                'icon' => 'heroicon-o-ticket',
                'count' => $new > 0 ? $new : null,
                'alert' => false,
            ];
        }

        if (Calendar::canAccess()) {
            $boxes[] = [
                'label' => __('dashboard.home.boxes.calendar'),
                'detail' => __('dashboard.home.boxes.calendar_week'),
                'url' => Calendar::getUrl(),
                'icon' => 'heroicon-o-calendar-days',
                'count' => null,
                'alert' => false,
            ];
        }

        $pending = count((new AttentionItems($this->timezone()))->all());

        $boxes[] = [
            'label' => __('dashboard.home.boxes.attention'),
            'detail' => $pending > 0
                ? trans_choice('dashboard.home.boxes.attention_count', $pending, ['count' => $pending])
                : __('dashboard.home.boxes.attention_none'),
            'url' => '#ka-attention',
            'icon' => 'heroicon-o-exclamation-triangle',
            'count' => $pending > 0 ? $pending : null,
            'alert' => $pending > 0,
        ];

        return $boxes;
    }

    public function getDay(): CalendarDay
    {
        return CalendarDay::for(Carbon::now($this->timezone())->toDateString(), $this->timezone());
    }

    public function getCalendarUrl(): ?string
    {
        return Calendar::canAccess() ? Calendar::getUrl(['date' => Carbon::now($this->timezone())->toDateString()]) : null;
    }

    public function getDepartureUrl(string $uuid): ?string
    {
        return DepartureResource::canViewAny() ? DepartureResource::getUrl('edit', ['record' => $uuid]) : null;
    }

    private function home(): TodayHome
    {
        return new TodayHome($this->timezone());
    }

    private function timezone(): string
    {
        $timezone = Tenancy::current()?->timezone;

        return is_string($timezone) && $timezone !== ''
            ? $timezone
            : (string) config('kaiki.defaults.timezone', 'Europe/Athens');
    }
}
