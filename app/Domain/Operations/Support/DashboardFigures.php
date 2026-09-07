<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

use App\Domain\Availability\Support\LocalDay;
use App\Enums\BookingStatus;
use App\Enums\DepartureStatus;
use App\Enums\GuestDetailsStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\QuoteStatus;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Payment;
use App\Models\Quote;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The six figures on the operator's first screen (spec OPS-1, OPS-2).
 *
 * ## Why the definitions live here and are shown on the screen
 *
 * OPS-2 exists because *a dashboard figure nobody can define is a figure nobody
 * can trust*. An operator reconciles "revenue this week" against their bank once;
 * if it does not match they stop believing every other number on the page, and
 * they never say so. So each figure has exactly one implementation, a test whose
 * expected value is arithmetic a reader can check by hand, and a sentence on the
 * screen saying what it counts.
 *
 * ## Test bookings are excluded from every figure
 *
 * SAA-9's sandbox writes real rows with `is_test = true`. An operator who has
 * just finished onboarding has a handful of them, and a dashboard that counted
 * them would show revenue from money that does not exist — on the first morning
 * they use the product, which is the worst possible morning for it.
 *
 * Excluded *and said so on the screen*: silently dropping rows an operator can
 * see in their own bookings list is its own kind of wrong number.
 *
 * ## Every query is bounded
 *
 * NFR-6. Six aggregate queries and nothing that loads a collection to count it —
 * a dashboard whose cost grows with the catalogue is one that gets slower every
 * month the operator is successful.
 */
final class DashboardFigures
{
    /**
     * How close a departure has to be before being short of `min_pax` is worth
     * the operator's attention.
     *
     * Forty-eight hours, because that is roughly when the decision is real: a
     * departure two days out with three of its six needed passengers is one the
     * operator can still fill with a phone call or cancel while a guest can
     * still make other plans. A week out it is normal; six hours out it is too
     * late for either.
     */
    public const AT_RISK_HOURS = 48;

    public function __construct(private readonly string $timezone) {}

    public static function forCurrentTenant(): self
    {
        return new self(Tenancy::current()?->timezone ?: config('app.timezone', 'UTC'));
    }

    /**
     * Departures today and tomorrow, with the passengers on them.
     *
     * Two local days rather than "the next 48 hours": an operator's morning
     * question is *what is sailing today and what is sailing tomorrow*, and a
     * rolling window answers a question nobody asked — at four in the afternoon
     * it would silently start including the day after tomorrow.
     *
     * Cancelled departures are excluded and completed ones are not: a trip that
     * sailed this morning is still part of today, and removing it as the day
     * goes on would make the figure shrink under the operator while they watch.
     *
     * @return array{departures: int, pax: int}
     */
    public function todayAndTomorrow(): array
    {
        $today = LocalDay::today($this->timezone);
        $tomorrow = LocalDay::of(Carbon::now($this->timezone)->addDay()->toDateString(), $this->timezone);

        $row = Departure::query()
            ->where('status', '!=', DepartureStatus::Cancelled->value)
            ->where('starts_at_utc', '>=', $today->startUtc)
            ->where('starts_at_utc', '<', $tomorrow->endUtcExclusive)
            ->selectRaw('COUNT(*) as departures, COALESCE(SUM(seats_sold), 0) as pax')
            ->first();

        return [
            'departures' => (int) ($row->departures ?? 0),
            'pax' => (int) ($row->pax ?? 0),
        ];
    }

    /**
     * Departures within {@see self::AT_RISK_HOURS} that have not reached
     * `min_pax`.
     *
     * `min_pax > 0` is part of the definition rather than an optimisation: a
     * departure with no minimum cannot be short of it, and counting those would
     * make every private charter permanently "at risk".
     *
     * Already-guaranteed departures are excluded by the same arithmetic —
     * `seats_sold >= min_pax` is what guaranteed means — so the two cannot
     * disagree.
     */
    public function atRiskDepartures(): int
    {
        return Departure::query()
            ->whereIn('status', [DepartureStatus::Scheduled->value, DepartureStatus::Guaranteed->value])
            ->where('min_pax', '>', 0)
            ->whereColumn('seats_sold', '<', 'min_pax')
            ->where('starts_at_utc', '>=', Carbon::now('UTC'))
            ->where('starts_at_utc', '<', Carbon::now('UTC')->addHours(self::AT_RISK_HOURS))
            ->count();
    }

    /**
     * Live bookings still waiting for their passenger manifest.
     *
     * `NotRequired` and `Complete` are the other two states, and the distinction
     * matters: a product that never asks for guest details must not make this
     * number go up for ever.
     */
    public function pendingGuestDetails(): int
    {
        return $this->committedBookings()
            ->where('guest_details_status', GuestDetailsStatus::Pending->value)
            ->count();
    }

    /**
     * Quotes sent to a guest and not yet answered.
     *
     * `Sent` only. A `draft` is the operator's own unfinished work and belongs
     * on their to-do list rather than on a figure that reads as "waiting on
     * somebody else"; `expired` is a quote that has already had its answer by
     * default.
     */
    public function pendingQuotes(): int
    {
        return Quote::query()
            ->where('status', QuoteStatus::Sent->value)
            ->whereHas('booking', fn (Builder $query): Builder => $query->where('is_test', false))
            ->count();
    }

    /**
     * Money owed on bookings that are going to happen.
     *
     * `balance_cents` rather than `total_cents - paid_cents` computed here:
     * that column is maintained inside the booking transaction (PAY-10) and a
     * second subtraction on the dashboard is a second opinion about how much
     * somebody owes.
     *
     * Cancelled and refunded bookings are excluded — a cancelled booking with an
     * unpaid balance owes nothing, and including it would show an operator money
     * they are never going to collect.
     *
     * @return array{bookings: int, cents: int}
     */
    public function unpaidBalances(): array
    {
        $row = $this->committedBookings()
            ->where('balance_cents', '>', 0)
            ->selectRaw('COUNT(*) as bookings, COALESCE(SUM(balance_cents), 0) as cents')
            ->first();

        return [
            'bookings' => (int) ($row->bookings ?? 0),
            'cents' => (int) ($row->cents ?? 0),
        ];
    }

    /**
     * OPS-2, word for word: *"the sum of succeeded non-refund payments minus
     * succeeded refunds, within the tenant-timezone week starting Monday, for
     * non-test bookings only"*.
     *
     * Four decisions, each of which has an obvious wrong version:
     *
     * - **`paid_at`, not `created_at`.** A payment row is created before the
     *   redirect (PAY-8), so a guest who starts paying on Sunday night and
     *   finishes on Monday morning belongs to Monday's week. `created_at` would
     *   put the money in the week before it arrived.
     * - **Succeeded only.** `pending` and `processing` are somebody having
     *   *started* to pay.
     * - **Minus refunds, not excluding them.** A refund issued this week against
     *   a payment taken last week reduces this week — which is what the bank
     *   will show.
     * - **Non-test only**, like every other figure here.
     */
    public function revenueThisWeek(): int
    {
        $week = OperatingWeek::containing($this->timezone);

        $row = Payment::query()
            ->where('status', PaymentStatus::Succeeded->value)
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $week->startUtc)
            ->where('paid_at', '<', $week->endUtcExclusive)
            ->whereHas('booking', fn (Builder $query): Builder => $query->where('is_test', false))
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN kind = ? THEN -amount_cents ELSE amount_cents END), 0) as net',
                [PaymentKind::Refund->value],
            )
            ->first();

        return (int) ($row->net ?? 0);
    }

    /** The week the revenue figure is for, so the screen can name its dates. */
    public function week(): OperatingWeek
    {
        return OperatingWeek::containing($this->timezone);
    }

    /**
     * Are there any test bookings at all?
     *
     * The screen says "test bookings are not counted" only when there is
     * something not being counted. A permanent disclaimer about data the
     * operator does not have is noise, and noise is what teaches people to stop
     * reading the small print on a dashboard.
     */
    public function hasTestBookings(): bool
    {
        return Booking::query()->where('is_test', true)->exists();
    }

    /**
     * Bookings that are going to happen, or have: `confirmed`, `checked_in`,
     * `completed`.
     *
     * **Not `isLive()`**, which is the tempting one and is wrong here. That
     * predicate includes `draft` and the two quote states, and a draft booking
     * is a *hold* — fifteen minutes of somebody thinking about it. Counting a
     * hold as an unpaid balance would put money on the dashboard that nobody
     * owes and that disappears again on its own, and counting one as pending
     * guest details would ask an operator to chase a passport from a person who
     * has not booked.
     *
     * `pending_payment` is excluded for the same reason: it is a checkout in
     * flight, and it is either about to become confirmed or about to expire.
     *
     * `completed` is included, and that is deliberate: a trip that sailed with
     * money still owed is the most collectable debt an operator has.
     *
     * @return Builder<Booking>
     */
    private function committedBookings(): Builder
    {
        return Booking::query()
            ->where('is_test', false)
            ->whereIn('status', [
                BookingStatus::Confirmed->value,
                BookingStatus::CheckedIn->value,
                BookingStatus::Completed->value,
            ]);
    }
}
