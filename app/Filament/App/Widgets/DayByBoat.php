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
use App\Models\Departure;
use App\Models\User;
use App\Support\Tenancy;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Livewire\Attributes\On;

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

    /**
     * An answer given in «Χρειάζονται προσοχή» redraws the box at the top, so
     * its count drops with the row instead of a minute later.
     */
    #[On('attention-answered')]
    public function attentionAnswered(): void {}

    /** @return array<string, mixed>|null */
    public function getNext(): ?array
    {
        // A crew member sees the next one they sail, with their role, and the
        // operator's next one only when they are on none this week (2026-09-24).
        $mine = $this->isCrew() ? $this->home()->nextDeparture(sailingUserId: (int) auth()->id()) : null;
        $next = $mine ?? $this->home()->nextDeparture();

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

        $role = null;

        if ($mine !== null) {
            $role = (int) $departure->captain_user_id === (int) auth()->id()
                ? __('dashboard.home.next.role_captain')
                : __('dashboard.home.next.role_crew');
        }

        return [
            'when' => $when,
            'role' => $role,
            'mine' => $mine !== null,
            'boards' => $boards,
            'booked' => (int) $departure->seats_sold,
            'capacity' => (int) $departure->capacity,
            'time' => $next['starts']->format('H:i'),
            'trip' => (string) $departure->product?->title,
            'where' => collect([$departure->vessel?->name, $departure->product?->meetingPoint?->name])->filter()->implode(' · '),
            'aboard' => $next['aboard'],
            'expected' => $next['expected'],
            'percent' => $next['expected'] > 0 ? (int) round($next['aboard'] / $next['expected'] * 100) : 0,
            /*
             * **Ο έλεγχος είναι για τη σελίδα που ανοίγει, όχι για τη λίστα**
             * (Mike, 23/9: *«ως πλήρωμα, πατάω πάνω σε ένα trip και μου βγάζει
             * forbidden»*).
             *
             * Ήταν `canViewAny()`, που το πλήρωμα **το περνάει**: έχει
             * `ViewDepartures` και βλέπει κανονικά τη λίστα αναχωρήσεων. Ο
             * σύνδεσμος όμως πάει στη σελίδα *επεξεργασίας*, που θέλει
             * `ManageCatalogue` — άρα ο τίτλος της επόμενης εκδρομής ήταν
             * σύνδεσμος προς ένα 403, στην αρχική τους σελίδα.
             *
             * `canEdit()` ρωτάει ακριβώς αυτό που πρόκειται να συμβεί. Όποιος
             * δεν μπορεί, βλέπει το όνομα ως κείμενο — το blade το χειρίζεται
             * ήδη — και φτάνει στους επιβάτες από το ημερολόγιο, που είναι η
             * οθόνη που του ανήκει.
             */
            'url' => DepartureResource::canEdit($departure) ? DepartureResource::getUrl('edit', ['record' => $departure]) : null,
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
            // «Σάρωση εισιτηρίων» opens the camera on the boarding page, which
            // scans passenger after passenger and keeps working with no signal
            // (Mike, 2026-09-23). The list is still the Filament page.
            'url' => $qr ? route('filament.app.boarding', ['camera' => 1]) : CheckIn::getUrl(),
            'label' => $qr ? __('dashboard.home.next.scan') : __('dashboard.home.next.board'),
            'icon' => $qr ? 'heroicon-o-qr-code' : 'heroicon-o-list-bullet',
        ];
    }

    /**
     * The crew's home is the scan button, the next boat and today by boat —
     * nothing else (Mike, 2026-09-24). The boxes were bookings to chase and a
     * «Προσοχή» full of decisions crew are not the ones to take.
     */
    public function isCrew(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isCrewOnly();
    }

    /** @return list<array{label: string, detail: string, url: string, icon: string, count: int|null, alert: bool}> */
    public function getBoxes(): array
    {
        if ($this->isCrew()) {
            return [];
        }

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

        // The real number, not the first page's: a box stuck on «8» while the
        // operator answers row after row reads as a list that ignores them.
        $pending = (new AttentionItems($this->timezone()))->count();

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

    /**
     * Η μπάρα μιας αναχώρησης στο λωρίδιο του στόλου.
     *
     * Το ίδιο λάθος με τον σύνδεσμο της επόμενης εκδρομής, και μάλλον **αυτό**
     * πατούσε ο Mike (23/9): `canViewAny()` για μια σελίδα επεξεργασίας. Το
     * πλήρωμα βλέπει τον στόλο του, πατάει τη μπάρα με το όνομα της εκδρομής,
     * και παίρνει 403.
     *
     * Εδώ ο έλεγχος γίνεται στο μοντέλο και όχι στο uuid, γιατί το `canEdit()`
     * ρωτάει την πολιτική για **τη συγκεκριμένη** αναχώρηση. Αν δεν βρεθεί,
     * κανένας σύνδεσμος — το blade ζωγραφίζει `div` αντί για `a` και η μπάρα
     * μένει ακριβώς όπως είναι.
     */
    public function getDepartureUrl(string $uuid): ?string
    {
        $departure = Departure::query()->where('uuid', $uuid)->first();

        return $departure instanceof Departure && DepartureResource::canEdit($departure)
            ? DepartureResource::getUrl('edit', ['record' => $uuid])
            : null;
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
