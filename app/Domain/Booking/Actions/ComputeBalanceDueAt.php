<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Availability\LocalDateTimeResolver;
use App\Models\Booking;
use App\Models\RatePlan;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * When a balance falls due (spec PRC-27.1 … PRC-27.3, ADR-0018 Option A).
 *
 * ## The 09:00 floor is timezone arithmetic, not instant arithmetic
 *
 * The one thing in this Action that is easy to get wrong and impossible to
 * notice. `starts_at_utc->subDays(14)->setTime(9, 0)` sets nine o'clock **UTC**,
 * which is midday in Athens in summer and eleven in winter — and shifts by an
 * hour across each DST boundary, twice a year, silently.
 *
 * So the subtraction happens in **local calendar days** and the nine o'clock is
 * set in the tenant's timezone, through {@see LocalDateTimeResolver} — the same
 * distinction AVL-19 and AVL-20 draw between hours and calendar days, and what
 * #26 built that class for.
 *
 * ## Stored, never derived on read
 *
 * PRC-27.2. `bookings.balance_due_at` is written at confirmation so the
 * reminder scheduler and the "Υπόλοιπα" dashboard bucket can index it. Deriving
 * it would turn every "what is overdue" query into a full scan with arithmetic
 * in the `WHERE` clause.
 *
 * ## A late confirmation gets a different rule, and a hard cap
 *
 * PRC-27.3. A booking confirmed ten days before a departure whose balance was
 * due at fourteen would otherwise be born overdue — the guest would receive an
 * overdue notice for a balance they have had no chance to pay. So the due date
 * becomes confirmation plus 24 hours, **capped at two hours before departure**,
 * because a balance due after the boat has sailed is not a due date.
 */
final class ComputeBalanceDueAt
{
    /**
     * ADR-0018's platform default, when neither the plan nor the operator says.
     *
     * A constant rather than a literal because the payment settings screen
     * shows it as the placeholder on the operator's own field — an empty box
     * that reads as «14» is the only honest way to render "not set".
     */
    public const PLATFORM_DEFAULT_DAYS = 14;

    /**
     * @param  Carbon|null  $confirmedAt  when the booking confirmed; now by default
     * @return Carbon|null null when there is nothing left to pay
     */
    public function __invoke(Booking $booking, ?Carbon $confirmedAt = null): ?Carbon
    {
        if ($booking->balance_cents < 1) {
            // Paid in full. A due date on a settled booking would put it in the
            // reminder scheduler's query forever.
            return null;
        }

        $confirmedAt ??= now();
        $timezone = $this->timezoneFor($booking);
        $days = $this->daysFor($booking);

        $due = $this->localNineAmBefore($booking->starts_at_utc, $days, $timezone);

        if ($due->greaterThan($confirmedAt)) {
            return $due;
        }

        // PRC-27.3: confirmed too late for the ordinary rule.
        $late = $confirmedAt->copy()->addHours(24);
        $cap = $booking->starts_at_utc->copy()->subHours(2);

        // The cap can itself be in the past for a booking confirmed on the
        // morning of departure — a balance due two hours before a boat that
        // leaves in one is nonsense, so it collapses to the departure time and
        // the operator sees it as immediately overdue, which it is.
        return $late->greaterThan($cap) ? $cap : $late;
    }

    /**
     * N local calendar days before departure, at 09:00 in the tenant timezone.
     *
     * The subtraction is done on the **local date**, not on the UTC instant, so
     * a fortnight is fourteen calendar days however many hours that turns out to
     * be across a clock change.
     */
    private function localNineAmBefore(Carbon $startsAtUtc, int $days, string $timezone): Carbon
    {
        $localDate = Carbon::parse(
            LocalDateTimeResolver::localDate($startsAtUtc, $timezone),
            $timezone,
        )->subDays($days)->toDateString();

        $resolved = LocalDateTimeResolver::resolve($localDate, '09:00', $timezone);

        // `instant` is null only for a local time that does not exist — the
        // hour skipped by the spring-forward transition (ADR-0016). 09:00 is
        // never that hour in any timezone this product serves, but the fallback
        // is here rather than a `??` at the call site, because a null due date
        // silently drops a booking out of the reminder scheduler.
        return $resolved->instant ?? $startsAtUtc->copy()->subDays($days);
    }

    /**
     * The rate plan's override, then the tenant's, then the platform default.
     *
     * PRC-27.1's precedence, in that order. The rate plan's column is nullable
     * with **no** default precisely so that "not set" is distinguishable from
     * "set to the same number the tenant uses" — otherwise changing the tenant
     * setting would silently do nothing.
     */
    private function daysFor(Booking $booking): int
    {
        $plan = $this->ratePlanFor($booking);

        if ($plan?->balance_due_days_before_departure !== null) {
            return (int) $plan->balance_due_days_before_departure;
        }

        $tenant = Tenant::query()->find($booking->tenant_id);

        if ($tenant === null || $tenant->balance_due_days_before_departure === null) {
            // ADR-0018's platform default. Reached when a tenant predates the
            // column — the migration defaults it, but a row written around the
            // default is exactly what a fallback is for.
            return self::PLATFORM_DEFAULT_DAYS;
        }

        return (int) $tenant->balance_due_days_before_departure;
    }

    /**
     * The plan the price was resolved from, out of the frozen snapshot.
     *
     * From `price_snapshot`, not from a fresh resolution: the guest's balance
     * terms are the ones that applied when they booked, in the same spirit as
     * CXL-1's rule about the cancellation policy. A re-resolution would give a
     * booking made in March the September plan's terms.
     */
    private function ratePlanFor(Booking $booking): ?RatePlan
    {
        $id = $booking->price_snapshot['rate_plan_id'] ?? null;

        return is_int($id) ? RatePlan::query()->find($id) : null;
    }

    private function timezoneFor(Booking $booking): string
    {
        return LocalDateTimeResolver::timezone(Tenant::query()->find($booking->tenant_id));
    }
}
