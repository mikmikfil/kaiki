<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

use App\Contracts\WeatherProvider;
use App\Enums\DepartureStatus;
use App\Models\Departure;
use App\Models\Port;
use App\Models\Vessel;
use Illuminate\Support\Carbon;

/**
 * The forecast, joined to the sailings it threatens (ADR-0027, OPS-6).
 *
 * ## The join is the feature; the forecast is not
 *
 * An operator already has the weather on their phone. What they do not have is
 * *"Thursday and Friday are over Nefeli's limit — three departures, twenty-seven
 * passengers"*, and then a button to the screen that shows each of those guests
 * what they are owed. Everything here exists to close the gap between knowing
 * and acting; the numbers themselves are a commodity.
 *
 * ## It never decides, and it never cancels
 *
 * ADR-0027, and it is worth restating where the code is: a product that
 * cancelled a charter because an API said 7 Bft would eventually cancel one on
 * a day that turned out fine, and the operator would lose both the money and
 * the customer. This produces a list and a link to #121's preview, where a
 * person chooses.
 *
 * ## Not knowing is a different answer from calm
 *
 * A provider that cannot be reached yields **null**, and the panel then says
 * nothing rather than showing a row of zeros. A forecast of 0 Bft on a screen
 * an operator uses to decide whether to sail is the one wrong answer this
 * feature must never give.
 */
final class WeatherOutlook
{
    /**
     * How far ahead to look.
     *
     * Four days. A Greek meltemi is forecast reliably about that far out, and
     * the operator's decision — cancel now and give people notice, or wait — is
     * one they make in that window. Ten days of low-confidence forecast would
     * be ten rows nobody trusts.
     */
    public const DAYS = 4;

    public function __construct(
        private readonly WeatherProvider $provider,
        private readonly string $timezone,
    ) {}

    /**
     * Every vessel with a limit set, and the days ahead that exceed it.
     *
     * Vessels without `max_wind_bft` are absent entirely: an operator who has
     * not set a limit has not asked for this, and guessing one would put a
     * warning on their dashboard about a boat they know better than we do.
     *
     * @return list<array{vessel: Vessel, days: list<WindForecast>, rough: list<WindForecast>, departures: int, passengers: int}>
     */
    public function all(?Carbon $now = null): array
    {
        $now ??= Carbon::now($this->timezone);

        $vessels = Vessel::query()
            ->whereNotNull('max_wind_bft')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($vessels->isEmpty()) {
            return [];
        }

        $out = [];

        foreach ($vessels as $vessel) {
            $port = $this->portFor($vessel);

            if ($port === null) {
                continue;
            }

            $days = $this->provider->dailyWind(
                (float) $port->lat,
                (float) $port->lng,
                $this->timezone,
                self::DAYS,
            );

            // Null is "we do not know". Skipping the vessel is what makes the
            // panel silent rather than falsely calm.
            if ($days === null) {
                continue;
            }

            $rough = array_values(array_filter(
                $days,
                static fn (WindForecast $day): bool => $day->exceeds($vessel->max_wind_bft),
            ));

            if ($rough === []) {
                continue;
            }

            [$departures, $passengers] = $this->affected($vessel, $rough, $now);

            $out[] = [
                'vessel' => $vessel,
                'days' => $days,
                'rough' => $rough,
                'departures' => $departures,
                'passengers' => $passengers,
            ];
        }

        return $out;
    }

    /**
     * Where to ask about the weather for this boat.
     *
     * Its home port, because that is where it sails from and where the wind
     * that stops it is measured. Falling back to any port with coordinates is
     * better than nothing for an operator who has not set a home port — one
     * marina's wind is a reasonable proxy for another's a few miles away, and
     * the alternative is a boat that never gets a forecast for a field nobody
     * filled in.
     */
    private function portFor(Vessel $vessel): ?Port
    {
        $home = $vessel->homePort;

        if ($home instanceof Port && $home->lat !== null && $home->lng !== null) {
            return $home;
        }

        return Port::query()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->orderBy('id')
            ->first();
    }

    /**
     * What is booked on the rough days.
     *
     * Sailings only — a private charter blocked on the vessel calendar is not a
     * departure and has no passengers to count here. Cancelled ones are
     * excluded because they are already cancelled, and counting them would
     * inflate the figure an operator reads before deciding.
     *
     * `seats_sold` rather than a guest-row count: this is a headline figure on
     * a dashboard, and the manifest's own head count (OPS-9, which counts
     * infants) is the number that matters on a quay rather than here.
     *
     * @param  list<WindForecast>  $rough
     * @return array{0: int, 1: int}
     */
    private function affected(Vessel $vessel, array $rough, Carbon $now): array
    {
        $dates = array_map(static fn (WindForecast $day): string => $day->date, $rough);

        $row = Departure::query()
            ->where('vessel_id', $vessel->getKey())
            // `whereDate`, not `whereIn`, and the difference is a silent zero.
            //
            // `local_date` is a `date` column with a `date` cast, and Laravel
            // writes it to SQLite as `2026-09-10 00:00:00`. A `whereIn` against
            // `'2026-09-10'` therefore matches **nothing** — and the failure is
            // not an error, it is a panel that says a rough day has no
            // departures on it. `whereDate` compares the date part on both
            // engines.
            ->where(function ($query) use ($dates): void {
                foreach ($dates as $date) {
                    $query->orWhereDate('local_date', $date);
                }
            })
            ->whereNotIn('status', [DepartureStatus::Cancelled->value])
            ->where('starts_at_utc', '>=', $now->copy()->utc())
            ->selectRaw('COUNT(*) as departures, COALESCE(SUM(seats_sold), 0) as passengers')
            ->first();

        return [
            (int) ($row->departures ?? 0),
            (int) ($row->passengers ?? 0),
        ];
    }
}
