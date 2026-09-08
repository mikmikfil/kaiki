<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets;

use App\Domain\Operations\Support\CalendarDay;
use App\Domain\Operations\Support\FirstSteps;
use App\Filament\App\Pages\Calendar;
use App\Support\Tenancy;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

/**
 * Today's fleet, on the dashboard (spec OPS-1, OPS-3, OPS-4).
 *
 * ## The same `CalendarDay`, not a second one
 *
 * Every bar, every position and every turnaround margin comes from
 * {@see CalendarDay::for()} — the class #119 built for the full calendar page.
 * A compact copy of that arithmetic would be a second answer to *"where is this
 * bar"*, and the two would drift on the day the clocks change: `CalendarDay`
 * divides by the day's **real** length, which is 23 hours on 29 March in
 * Athens, and a hand-rolled `hours / 24` on a dashboard would put every bar an
 * hour out of place for one day a year with nothing on screen to explain it.
 *
 * So this widget contributes no arithmetic at all. It chooses a date — today —
 * and renders smaller.
 *
 * ## Why it belongs above the fold rather than behind a menu item
 *
 * OPS-3 already gives the operator a calendar page. What it does not give them
 * is the answer to the question they actually open the laptop with: *is
 * anything wrong with today?* A timeline of three boats answers that in a
 * glance — which trip is short, which boat is out, where the gaps are — and the
 * page behind it is for when the answer is yes.
 *
 * The whole strip is a link to that page, on the day it is showing.
 */
class TodayAtSea extends Widget
{
    protected static string $view = 'filament.app.widgets.today-at-sea';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    /**
     * A minute.
     *
     * Bars move when somebody books, cancels or blocks — none of which happens
     * on a ten-second cadence, and all of which the operator usually did
     * themselves in another tab.
     */
    protected static ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        // Negating `applies()` is not enough on its own: with no tenant it
        // answers false, and the negation would then say *show me* — on the one
        // request that cannot query anything. The tenant check has to be its
        // own clause rather than a consequence of somebody else's.
        if (! Tenancy::check()) {
            return false;
        }

        // Nothing to draw before there is a boat, and `FirstSteps` is already
        // telling that operator to add one.
        return ! FirstSteps::applies();
    }

    public function getDay(): CalendarDay
    {
        return CalendarDay::for($this->today(), $this->timezone());
    }

    /** The full calendar, opened on the day this strip is showing. */
    public function getCalendarUrl(): string
    {
        return Calendar::getUrl(['date' => $this->today()]);
    }

    public function getHeadingDate(): string
    {
        return Carbon::now($this->timezone())->translatedFormat('l j F');
    }

    /**
     * Today on the **operator's** clock, not the server's.
     *
     * A dashboard that rolls over to tomorrow at 02:00 Athens time, because the
     * server is on UTC, shows an empty strip to somebody still working the
     * evening's last charter.
     */
    private function today(): string
    {
        return Carbon::now($this->timezone())->toDateString();
    }

    private function timezone(): string
    {
        $timezone = Tenancy::current()?->timezone;

        return is_string($timezone) && $timezone !== ''
            ? $timezone
            : (string) config('kaiki.defaults.timezone', 'Europe/Athens');
    }
}
