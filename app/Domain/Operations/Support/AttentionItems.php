<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

use App\Enums\BookingStatus;
use App\Enums\DepartureStatus;
use App\Enums\GuestDetailsStatus;
use App\Enums\QuoteStatus;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\IcalSource;
use App\Models\Quote;
use App\Support\Format\MoneyFormatter;
use Illuminate\Support\Carbon;

/**
 * The things an operator has to decide today (spec OPS-1, OPS-2).
 *
 * ## This is a decision list, not an error list
 *
 * The distinction is the whole design, and it is what keeps this from colliding
 * with OPS-21's consolidated failure feed. **That** feed is for things the
 * system tried and could not do — a payment that would not settle, a myDATA
 * submission that was refused, an SMS that bounced — and every row on it has a
 * retry button, because retrying is the answer.
 *
 * **This** is for things the system did correctly and cannot resolve on its own,
 * because they need a person to choose: a boat four passengers short of its
 * minimum four hours before it sails, a guest who has not sent passport details
 * for a trip on Thursday, money owed past its due date. Nothing here can be
 * retried. Every row is a question.
 *
 * Mixing the two produces a list where half the rows want a click and half want
 * a judgement, and an operator learns to skim it.
 *
 * ## Ordered by when the chance to act runs out, never by severity
 *
 * A "critical" badge on a row that can wait until Friday, above a "warning" on
 * a boat leaving in three hours, is a list that has to be read in full to be
 * used — which is the same as not having a list. So the sort key is the
 * deadline, and severity is only a colour.
 *
 * ## Every definition matches the dashboard's, deliberately
 *
 * OPS-2's argument applies twice over here: a figure that says *"3 at risk"* and
 * a list underneath it showing four rows is worse than either alone. So the
 * predicates below are the same ones {@see DashboardFigures} uses — the
 * at-risk window, the committed-booking filter, the exclusion of test bookings
 * — and where this class needs the rows rather than the count, it asks the same
 * question in the same words.
 */
final class AttentionItems
{
    /**
     * How far ahead a sailing counts as needing a decision now.
     *
     * The same window {@see DashboardFigures::atRiskDepartures()} uses, because
     * the figure and this list must not disagree about which boats are at risk.
     */
    public const AT_RISK_HOURS = 48;

    /**
     * How close a departure has to be before missing details are urgent.
     *
     * Passenger details are chased by automatic reminders (BKG-16) for days
     * beforehand. This is the point at which the reminders have not worked and
     * a person needs to pick up a phone — the manifest has to exist before the
     * boat leaves, not shortly after.
     */
    public const DETAILS_HOURS = 48;

    /** The most rows one panel shows before it stops being readable. */
    private const LIMIT = 8;

    public function __construct(private readonly string $timezone) {}

    /**
     * Everything wanting a decision, soonest deadline first.
     *
     * @return list<AttentionItem>
     */
    public function all(?Carbon $now = null): array
    {
        $now ??= Carbon::now('UTC');

        $items = [
            ...$this->underMinimum($now),
            ...$this->missingGuestDetails($now),
            ...$this->overdueBalances($now),
            ...$this->expiringQuotes($now),
            ...$this->brokenCalendars(),
        ];

        usort(
            $items,
            static fn (AttentionItem $a, AttentionItem $b): int => $a->deadlineSortKey() <=> $b->deadlineSortKey(),
        );

        return array_slice($items, 0, self::LIMIT);
    }

    /**
     * Sailings that will not reach their minimum, close enough to matter.
     *
     * The operator's real question is *"do I run it short or cancel it"*, and
     * both answers cost money — which is exactly why the product must not pick
     * one. The row states the gap and links to the departure.
     *
     * @return list<AttentionItem>
     */
    private function underMinimum(Carbon $now): array
    {
        $departures = Departure::query()
            ->with(['product', 'vessel'])
            ->whereIn('status', [DepartureStatus::Scheduled->value, DepartureStatus::Guaranteed->value])
            ->where('min_pax', '>', 0)
            ->whereColumn('seats_sold', '<', 'min_pax')
            ->where('starts_at_utc', '>=', $now)
            ->where('starts_at_utc', '<', $now->copy()->addHours(self::AT_RISK_HOURS))
            ->orderBy('starts_at_utc')
            ->limit(self::LIMIT)
            ->get();

        return $departures->map(fn (Departure $departure): AttentionItem => new AttentionItem(
            key: 'departure:' . $departure->getKey(),
            severity: AttentionSeverity::Critical,
            title: (string) __('attention.under_minimum.title', [
                'trip' => (string) $departure->product?->title,
                'time' => $departure->local_date->toDateString() . ' ' . substr($departure->local_time, 0, 5),
            ]),
            detail: (string) __('attention.under_minimum.detail', [
                'sold' => $departure->seats_sold,
                'minimum' => $departure->min_pax,
                'vessel' => (string) $departure->vessel?->name,
            ]),
            deadline: $this->local($departure->starts_at_utc),
        ))->all();
    }

    /**
     * Bookings sailing soon whose passenger list is still incomplete.
     *
     * `Pending` only — `NotRequired` is a product that never asks, and putting
     * those on a list of problems would make every operator who does not
     * collect documents see a permanent backlog they cannot clear.
     *
     * @return list<AttentionItem>
     */
    private function missingGuestDetails(Carbon $now): array
    {
        $bookings = Booking::query()
            ->with('product')
            ->where('is_test', false)
            ->whereIn('status', [
                BookingStatus::PendingPayment->value,
                BookingStatus::Confirmed->value,
            ])
            ->where('guest_details_status', GuestDetailsStatus::Pending->value)
            ->where('starts_at_utc', '>=', $now)
            ->where('starts_at_utc', '<', $now->copy()->addHours(self::DETAILS_HOURS))
            ->orderBy('starts_at_utc')
            ->limit(self::LIMIT)
            ->get();

        return $bookings->map(fn (Booking $booking): AttentionItem => new AttentionItem(
            key: 'details:' . $booking->getKey(),
            severity: AttentionSeverity::Warning,
            title: (string) __('attention.guest_details.title', ['reference' => $booking->reference]),
            detail: (string) __('attention.guest_details.detail', [
                'guest' => $booking->guest_name,
                'trip' => (string) $booking->product?->title,
            ]),
            deadline: $this->local($booking->starts_at_utc),
        ))->all();
    }

    /**
     * Money whose due date has passed.
     *
     * `balance_due_at` in the past and `balance_cents` still positive. The
     * column is maintained inside the booking transaction (PAY-10), so this
     * never subtracts anything itself — a second opinion about how much
     * somebody owes is how a screen and an invoice end up disagreeing.
     *
     * @return list<AttentionItem>
     */
    private function overdueBalances(Carbon $now): array
    {
        $bookings = Booking::query()
            ->where('is_test', false)
            ->whereIn('status', [
                BookingStatus::PendingPayment->value,
                BookingStatus::Confirmed->value,
            ])
            ->where('balance_cents', '>', 0)
            ->whereNotNull('balance_due_at')
            ->where('balance_due_at', '<', $now)
            ->orderBy('balance_due_at')
            ->limit(self::LIMIT)
            ->get();

        return $bookings->map(fn (Booking $booking): AttentionItem => new AttentionItem(
            key: 'balance:' . $booking->getKey(),
            severity: AttentionSeverity::Warning,
            title: (string) __('attention.balance.title', ['reference' => $booking->reference]),
            detail: (string) __('attention.balance.detail', [
                'guest' => $booking->guest_name,
                'amount' => MoneyFormatter::format(
                    $booking->balance_cents,
                    null,
                    MoneyFormatter::currency(),
                ),
            ]),
            deadline: $this->local($booking->balance_due_at),
        ))->all();
    }

    /**
     * Quotes the guest has not answered and that are about to lapse.
     *
     * `Sent` only, matching the dashboard figure. A quote that expires is a
     * charter that quietly did not happen, and the operator's chance to ring
     * them ends when it does.
     *
     * @return list<AttentionItem>
     */
    private function expiringQuotes(Carbon $now): array
    {
        $quotes = Quote::query()
            ->with('booking')
            ->where('status', QuoteStatus::Sent->value)
            // `valid_until`, which is the column — a quote has no `expires_at`.
            // `expired_at` is the *stamp* recorded when the sweeper lapses one,
            // and is null for every quote this list is about.
            ->where('valid_until', '>=', $now)
            ->where('valid_until', '<', $now->copy()->addHours(self::AT_RISK_HOURS))
            // The same test-booking exclusion the dashboard figure makes; a
            // quote's own row carries no such flag, so it is asked of the
            // booking the quote belongs to.
            ->whereHas('booking', static fn ($query) => $query->where('is_test', false))
            ->orderBy('valid_until')
            ->limit(self::LIMIT)
            ->get();

        return $quotes->map(fn (Quote $quote): AttentionItem => new AttentionItem(
            key: 'quote:' . $quote->getKey(),
            severity: AttentionSeverity::Info,
            title: (string) __('attention.quote.title', [
                'reference' => (string) $quote->booking?->reference,
            ]),
            detail: (string) __('attention.quote.detail'),
            deadline: $this->local($quote->valid_until),
        ))->all();
    }

    /**
     * External calendars that have stopped being readable (OPS-15).
     *
     * The one row here with no deadline of its own, and the most dangerous: a
     * calendar that silently stopped syncing is a boat that looks free while
     * somebody else has sold it. It sorts last only because everything above it
     * has a clock running.
     *
     * @return list<AttentionItem>
     */
    private function brokenCalendars(): array
    {
        $sources = IcalSource::query()
            ->with('vessel')
            ->where('consecutive_failures', '>=', IcalSource::ATTENTION_THRESHOLD)
            ->orderByDesc('consecutive_failures')
            ->limit(self::LIMIT)
            ->get();

        return $sources->map(fn (IcalSource $source): AttentionItem => new AttentionItem(
            key: 'ical:' . $source->getKey(),
            severity: AttentionSeverity::Warning,
            title: (string) __('attention.calendar.title', ['name' => $source->name]),
            detail: (string) __('attention.calendar.detail', [
                'vessel' => (string) $source->vessel?->name,
                'count' => $source->consecutive_failures,
            ]),
            deadline: null,
        ))->all();
    }

    /**
     * The same instant, on the operator's clock.
     *
     * The panel shows "in 3 hours" — which is timezone-agnostic — but the
     * absolute time is on hover, and that is the one somebody reads when the
     * relative answer is not enough. A UTC timestamp shown to an operator in
     * Aegina is wrong by three hours in summer, and wrong in the direction that
     * makes a boat look later than it is.
     *
     * Sorting is unaffected: converting a zone does not move the instant.
     */
    private function local(?Carbon $at): ?Carbon
    {
        return $at?->copy()->setTimezone($this->timezone);
    }
}
