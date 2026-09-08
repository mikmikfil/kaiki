<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets;

use App\Contracts\WeatherProvider;
use App\Domain\Operations\Support\FirstSteps;
use App\Domain\Operations\Support\WeatherOutlook as Outlook;
use App\Domain\Operations\Support\WindForecast;
use App\Filament\App\Pages\Calendar;
use App\Models\Vessel;
use App\Support\Tenancy;
use Filament\Widgets\Widget;

/**
 * «Καιρός» — the days ahead that are over a boat's limit (ADR-0027, OPS-6).
 *
 * ## It sits between the fleet strip and the decision list on purpose
 *
 * The strip says what is happening today. This says what is coming. The
 * decision list below says what to do about it — and its own weather rows are
 * about sailings *already* short of minimum, which is a different problem.
 * Reading the three top to bottom is the morning.
 *
 * ## Only boats with a limit, and only when there is something to say
 *
 * Two silences, and both are deliberate. A vessel with no `max_wind_bft` is
 * absent because its operator never asked for this. A vessel whose next four
 * days are all inside its limit is absent because "the weather is fine" is not
 * news — and a panel that is always on screen is one people stop reading before
 * the week it matters.
 *
 * The widget disappears entirely when every boat is in one of those two states,
 * which on a calm week is most of them.
 *
 * ## A forecast that could not be fetched shows nothing, never zero
 *
 * {@see Outlook} returns no row for a vessel whose provider call failed. A
 * panel reporting 0 Bft because a request timed out would read as "calm on
 * Thursday" on the screen an operator uses to decide whether to sail, which is
 * the single worst thing this feature could do.
 */
class WeatherOutlook extends Widget
{
    protected static string $view = 'filament.app.widgets.weather-outlook';

    /** Between the fleet strip (2) and the decision list (4). */
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    /**
     * Ten minutes.
     *
     * The provider itself is cached for three hours, so a shorter poll would
     * re-render the same numbers; a longer one would leave a stale panel up
     * after an operator set a vessel's limit and came back to check.
     */
    protected static ?string $pollingInterval = '600s';

    public static function canView(): bool
    {
        return Tenancy::check() && ! FirstSteps::applies() && self::rows() !== [];
    }

    /**
     * @return list<array{vessel: Vessel, days: list<WindForecast>, rough: list<WindForecast>, departures: int, passengers: int}>
     */
    public function getRows(): array
    {
        return self::rows();
    }

    /** The weather-cancellation preview, which is where a decision is made. */
    public function getCalendarUrl(): string
    {
        return Calendar::getUrl();
    }

    /**
     * @return list<array{vessel: Vessel, days: list<WindForecast>, rough: list<WindForecast>, departures: int, passengers: int}>
     */
    private static function rows(): array
    {
        $tenant = Tenancy::current();

        if ($tenant === null) {
            return [];
        }

        return (new Outlook(app(WeatherProvider::class), $tenant->timezone))->all();
    }
}
