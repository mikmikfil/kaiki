<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Domain\Availability\LocalDateTimeResolver;
use App\Filament\App\Resources\DepartureResource;
use App\Models\Booking;
use App\Models\Departure;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * The days a crew member can see, and the one place that decides them.
 *
 * ## What TEN-8 actually says
 *
 * > `crew` — read-only access to **departures within a configurable window
 * > (default today and tomorrow)**, the pax list, check-in actions and manifest
 * > view; no pricing, no financials, no guest documents beyond what the manifest
 * > shows.
 *
 * The window is not decoration. A skipper needs today's sailing and tomorrow's
 * so they can look at the evening before; they have no reason to hold the
 * season's booking history, and the difference matters because that history is
 * every guest's name, email address and telephone number. A phone left on a
 * boat is the threat model, and «read-only» is no comfort at all against it.
 *
 * ## Why this class exists rather than a second copy of the dates
 *
 * The window was implemented on {@see DepartureResource}
 * and nowhere else, so a crew member reached the departures of today and
 * tomorrow — and, one click away, **the entire bookings list** and a calendar
 * they could page back through by a year. The screens disagreed because each was
 * asked separately, which is what happens to a rule that lives inside whichever
 * screen was written first.
 *
 * Found while writing the manual's chapter on which role sees what: the table
 * had to state a single answer per screen, and there was not one.
 *
 * ## Who is "crew"
 *
 * Anyone without {@see Capability::ManageCatalogue}, which owner and manager
 * both hold and crew does not. Phrased as the absence of a capability rather
 * than as `role === Crew`, because a person can hold two role rows and the union
 * of their capabilities is what governs everywhere else.
 */
final class CrewWindow
{
    /**
     * Whether the window binds the person making this request.
     *
     * A request with nobody signed in gets the window too. It is the safe way
     * round: a console command or a job that reaches one of these queries
     * without a user should not be handed the widest possible result set by
     * default, and the two places that legitimately need everything
     * ({@see Tenancy::withoutTenancy} and the export actions) do
     * not go through here.
     */
    public static function applies(): bool
    {
        return Auth::user()?->hasCapability(Capability::ManageCatalogue) !== true;
    }

    /** Days after today, from configuration. Zero means today alone. */
    public static function days(): int
    {
        return max(0, (int) config('kaiki.panel.crew_departure_window_days', 1));
    }

    /**
     * Today, in the **tenant's** timezone rather than the server's.
     *
     * At 23:30 in Athens the server's today is already tomorrow, and the crew
     * member checking the evening before a 07:00 sailing is exactly the person
     * who finds that out.
     */
    public static function firstDay(): CarbonImmutable
    {
        return CarbonImmutable::now(LocalDateTimeResolver::timezone())->startOfDay();
    }

    public static function lastDay(): CarbonImmutable
    {
        return self::firstDay()->addDays(self::days());
    }

    public static function covers(CarbonImmutable $day): bool
    {
        return $day->startOfDay()->betweenIncluded(self::firstDay(), self::lastDay());
    }

    /**
     * Narrow a departure query to the window, or leave it alone.
     *
     * @param  Builder<Departure>  $query
     * @return Builder<Departure>
     */
    public static function scopeDepartures(Builder $query): Builder
    {
        if (! self::applies()) {
            return $query;
        }

        return $query
            ->whereDate('local_date', '>=', self::firstDay()->toDateString())
            ->whereDate('local_date', '<=', self::lastDay()->toDateString());
    }

    /**
     * Narrow a booking query to bookings on departures inside the window.
     *
     * `whereHas` rather than a join, because a booking whose departure has been
     * deleted must not survive the filter by having no date to test — and a
     * `leftJoin` would let exactly that through.
     *
     * @param  Builder<Booking>  $query
     * @return Builder<Booking>
     */
    public static function scopeBookings(Builder $query): Builder
    {
        if (! self::applies()) {
            return $query;
        }

        return $query->whereHas(
            'departure',
            static fn (Builder $departures): Builder => $departures
                ->whereDate('local_date', '>=', self::firstDay()->toDateString())
                ->whereDate('local_date', '<=', self::lastDay()->toDateString()),
        );
    }
}
