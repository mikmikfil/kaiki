<?php

declare(strict_types=1);

namespace App\Mail\Support;

use App\Domain\Booking\Actions\RefundBooking;
use App\Domain\Booking\Support\BoardingPasses;
use App\Domain\Booking\Support\BookingCalendarInvite;
use App\Domain\Booking\Support\RefundEntitlement;
use App\Domain\Notifications\Support\ReviewRequestSettings;
use App\Enums\GuestDetailsStatus;
use App\Enums\NotificationTemplate;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\QuoteLineKind;
use App\Enums\QuoteStatus;
use App\Enums\WeatherChoice;
use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\Payment;
use App\Models\Port;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteLineItem;
use App\Models\Tenant;
use App\Models\Voucher;
use App\Support\Tenancy;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Everything the booking email says about a booking, worked out once
 * (product owner, 2026-09-17: design A «Κάρτα εισιτηρίου»).
 *
 * The template used to show a reference, a date and a meeting point's name, and
 * a guest wrote back to ask what time to be at the quay. The email now carries
 * the whole trip: when to arrive, when it leaves and comes back, where to meet
 * and how to find it, who is booked and what was paid, what to bring, the
 * cancellation terms, how to reach the operator, the ticket and — while it is
 * still missing — the passenger details.
 *
 * ## Every message on the card, each with its own facts
 *
 * The confirmation, a change and the day-before reminder are the full ticket.
 * Every other message is the same card with a short box of **the facts that
 * message is about** under it, and one button that does the thing it asks for
 * (the email gallery review, 2026-09-17):
 *
 * | Message | Facts | Button |
 * |---|---|---|
 * | cancellation | refund amount and where it goes | booking page |
 * | passenger details | the deadline | the details form |
 * | balance due / overdue | total, paid, what is left, by when | pay (the booking page) |
 * | ναυλοσύμφωνο | the real acceptance deadline | the acceptance form |
 * | weather choice | what is owed, choose by when | choose (the booking page) |
 * | weather applied | what was done, how much | booking page |
 * | voucher expiry | a voucher card: code, amount left, expiry | the voucher page |
 * | quote | the items, total, validity, deposit | the quote page |
 * | review | the trip it asks about | the Google review page |
 *
 * «Υπόλοιπο» appears only where it is news: the confirmation, a change and the
 * balance messages. On a cancellation, a quote or a review request it was a
 * figure about money nobody is going to ask for.
 *
 * ## Arithmetic here, markup in the views
 *
 * Both halves of the email (NTF-6's HTML and plain text) render from this, so
 * they cannot disagree about the check-in time. Every value is already a
 * string in the booking's own locale, or null when there is nothing to say —
 * the views print what exists and leave out what does not, rather than a label
 * beside an empty value.
 *
 * ## Extras first, the booking second
 *
 * The sender knows things the booking row does not: the refund a cancellation
 * put in motion, the voucher a reminder is about, the quote just sent, the
 * weather deadline. They arrive in `$extra`. Each also falls back to what can be
 * read off the booking, so the branding preview and the template tests, which
 * pass no extras, still render a sensible message.
 *
 * ## Frozen where the booking froze it
 *
 * The party and its prices come off `pax_breakdown` and `extras_snapshot`, and
 * the cancellation sentence off `policy_snapshot` — what the guest agreed to,
 * never what the operator has typed since. The meeting point, the things to
 * bring and the operator's phone are read live, because a moved pontoon is news
 * the guest needs.
 */
final class BookingMailDetails
{
    /** The messages where what is left to pay is news. */
    private const SHOWS_BALANCE = [
        NotificationTemplate::BookingConfirmed,
        NotificationTemplate::BookingChanged,
        NotificationTemplate::BalanceDueReminder,
        NotificationTemplate::BalanceOverdue,
    ];

    /**
     * @param  list<array{label: string, amount: string}>  $party
     * @param  list<string>  $bring
     * @param  list<array{label: string, value: string}>  $cardRows
     * @param  list<array{label: string, value: string}>  $facts
     */
    public function __construct(
        public readonly bool $full,
        public readonly string $locale,
        public readonly ?string $greeting,
        public readonly ?string $trip,
        public readonly ?string $boat,
        public readonly string $day,
        public readonly ?string $checkIn,
        public readonly string $departure,
        public readonly ?string $return,
        public readonly ?string $meetingName,
        public readonly ?string $meetingAddress,
        public readonly ?string $meetingInstructions,
        public readonly ?string $mapUrl,
        public readonly array $party,
        public readonly ?string $discount,
        public readonly ?string $discountCode,
        public readonly ?string $total,
        public readonly ?string $paid,
        public readonly ?string $balance,
        public readonly ?string $balanceDue,
        public readonly ?string $refund,
        public readonly array $bring,
        public readonly ?string $policy,
        public readonly ?string $phone,
        public readonly ?string $email,
        public readonly string $manageUrl,
        public readonly ?string $ticketUrl,
        public readonly ?string $detailsUrl,
        public readonly ?string $detailsBy,
        public readonly ?string $reviewUrl,
        public readonly string $operator,
        public readonly ?string $cardEyebrow,
        public readonly string $cardTitle,
        public readonly array $cardRows,
        public readonly array $facts,
        public readonly ?string $factsNote,
        public readonly bool $showParty,
        public readonly string $actionLabel,
        public readonly string $actionUrl,
        public readonly ?string $deadline,
        /**
         * "Add to calendar", on the three messages that carry the whole trip
         * (2026-09-18). Two links because they serve two different guests: the
         * `.ics` is what a phone's mail client hands to iOS or Android, and the
         * Google link is one click for somebody reading in a browser. Null on
         * every other message, and on a booking with no departure to put in a
         * calendar — the views print what exists.
         */
        public readonly ?string $calendarUrl = null,
        public readonly ?string $calendarGoogleUrl = null,
        /**
         * One boarding code per passenger, on the same three messages
         * (2026-09-23), and only when {@see BoardingPasses} says there is one
         * to show — QR boarding on for this operator, a ticketed booking, a
         * trip not yet sailed. Empty otherwise, and both halves read it: the
         * HTML draws the codes, the plain text says where they are.
         *
         * @var list<array{name: string, code: string, guest: BookingGuest}>
         */
        public readonly array $boardingPasses = [],
    ) {}

    /** @param array<string, mixed> $extra */
    public static function for(Booking $booking, NotificationTemplate $template, string $locale, array $extra = []): self
    {
        $booking->loadMissing(['product.meetingPoint', 'vessel']);

        $product = $booking->product;
        $port = $product?->meetingPoint;
        // The branding page previews this with a booking it never saves, and so
        // has no tenant id: the operator whose page it is stands in.
        $tenant = $booking->tenant_id === null
            ? Tenancy::current()
            : Tenancy::withoutTenancy(static fn (): ?Tenant => Tenant::query()->find($booking->tenant_id));

        $operator = $tenant instanceof Tenant ? $tenant->name : (string) config('app.name');
        $zone = $tenant instanceof Tenant && $tenant->timezone !== '' ? $tenant->timezone : (string) config('app.timezone');

        $starts = Carbon::parse($booking->local_date->toDateString() . ' ' . substr((string) $booking->local_time, 0, 5))
            ->locale($locale);

        $euros = static fn (int $cents): string => number_format($cents / 100, 2, ',', '.') . ' €';
        $at = static fn (CarbonInterface $moment, string $format): string => Carbon::instance($moment)->copy()
            ->setTimezone($zone)
            ->locale($locale)
            ->isoFormat($format);

        $manageUrl = route('guest.booking', ['token' => $booking->manage_token]);
        // Only where the message is the whole trip, and only where there is a
        // departure: the branding preview renders an unsaved booking, and a
        // calendar entry for one would land on 1 January 1970.
        $calendar = $template->carriesWholeTrip() ? BookingCalendarInvite::for($booking, $locale) : null;
        $calendar = $calendar instanceof BookingCalendarInvite && $calendar->isAvailable() ? $calendar : null;
        $formUrl = self::blank($booking->guest_details_token)
            ? null
            : route('guest.details', ['token' => $booking->guest_details_token]);
        $detailsPending = $booking->guest_details_status === GuestDetailsStatus::Pending;

        $checkIn = $product instanceof Product && $product->check_in_offset_minutes > 0
            ? $starts->copy()->subMinutes($product->check_in_offset_minutes)->format('H:i')
            : null;
        $return = $product instanceof Product && $product->duration_minutes > 0
            ? $starts->copy()->addMinutes($product->duration_minutes)->format('H:i')
            : null;

        $trip = $product instanceof Product ? self::text($product->getTranslation('title', $locale, true)) : null;
        $boat = self::text($booking->vessel?->name);
        $showsBalance = in_array($template, self::SHOWS_BALANCE, true);
        $balanceCents = (int) $booking->balance_cents;
        $paidCents = (int) $booking->paid_cents;

        // The card, the facts and the button, per message. Defaults first: the
        // booking card, the reference, the guest's own page.
        $eyebrow = collect([$trip, $boat])->filter()->implode(' · ');
        $cardEyebrow = $eyebrow === '' ? null : $eyebrow;
        $cardTitle = $starts->isoFormat('dddd D MMMM YYYY');
        $cardRows = array_values(array_filter([
            $checkIn === null ? null : ['label' => __('mail.common.check_in', [], $locale), 'value' => $checkIn],
            ['label' => __('mail.common.departure', [], $locale), 'value' => $starts->format('H:i')],
            $return === null ? null : ['label' => __('mail.common.return', [], $locale), 'value' => $return],
        ]));

        $facts = [['label' => __('mail.common.reference', [], $locale), 'value' => (string) $booking->reference]];
        $note = null;
        $action = [__('mail.common.manage_booking', [], $locale), $manageUrl];
        $party = self::party($booking, $locale, $euros);
        $total = (int) $booking->total_cents > 0 ? $euros((int) $booking->total_cents) : null;

        // The discount code, if one was used. `discount_cents` is the booking's
        // own column and the snapshot carries what was applied, so the email
        // keeps saying so after the code itself is edited or deleted.
        $discountCents = (int) $booking->discount_cents;
        $snapshotCode = is_array($booking->price_snapshot)
            ? ($booking->price_snapshot['discount_code'] ?? null)
            : null;
        $discountLabel = is_array($snapshotCode) && trim((string) ($snapshotCode['code'] ?? '')) !== ''
            ? (string) $snapshotCode['code']
            : null;
        $showParty = $template->carriesWholeTrip();
        $deadline = null;
        $reviewUrl = null;
        $refund = null;

        switch ($template) {
            case NotificationTemplate::BookingCancelled:
                $refundCents = array_key_exists('refunded_cents', $extra)
                    ? (int) $extra['refunded_cents']
                    : (int) $booking->refunded_cents;

                if ($refundCents > 0) {
                    $refund = $euros($refundCents);
                    $voucher = self::voucherIssuedFor($booking);

                    $facts[] = ['label' => __('mail.common.refunded', [], $locale), 'value' => $refund];

                    // Cash or a transfer cannot go back on its own, and a guest
                    // told «to the payment method you used» would wait for money
                    // on a card that never had it (2026-09-23). So the email
                    // says which part comes back how.
                    [$byCard, $byHand] = self::refundSplit($booking);

                    $facts[] = [
                        'label' => __('mail.common.refund_method', [], $locale),
                        'value' => match (true) {
                            $voucher instanceof Voucher => __('mail.common.refund_to_voucher', ['code' => $voucher->code], $locale),
                            $byHand > 0 && $byCard > 0 => __('mail.common.refund_card_part', ['amount' => $euros($byCard)], $locale)
                                . ' · ' . __('mail.common.refund_by_hand', ['amount' => $euros($byHand)], $locale),
                            $byHand > 0 => __('mail.common.refund_by_hand', ['amount' => $euros($byHand)], $locale),
                            default => __('mail.common.refund_to_card', [], $locale),
                        },
                    ];
                }
                break;

            case NotificationTemplate::GuestDetailsRequested:
            case NotificationTemplate::GuestDetailsReminder48h:
            case NotificationTemplate::GuestDetailsReminder24h:
                $deadline = $at($booking->guest_details_deadline_at ?? self::detailsDeadline($booking, $starts), 'dddd D MMMM YYYY, HH:mm');
                $facts[] = ['label' => __('mail.common.details_by_label', [], $locale), 'value' => $deadline];

                if ($formUrl !== null) {
                    $action = [__('mail.common.details_button', [], $locale), $formUrl];
                }
                break;

            case NotificationTemplate::BalanceDueReminder:
            case NotificationTemplate::BalanceOverdue:
                if ($total !== null) {
                    $facts[] = ['label' => __('mail.common.total', [], $locale), 'value' => $total];
                }
                if ($paidCents > 0) {
                    $facts[] = ['label' => __('mail.common.paid', [], $locale), 'value' => $euros($paidCents)];
                }
                if ($balanceCents > 0) {
                    $facts[] = ['label' => __('mail.common.balance_to_pay', [], $locale), 'value' => $euros($balanceCents)];
                }
                if ($booking->balance_due_at !== null) {
                    $deadline = $at($booking->balance_due_at, 'dddd D MMMM YYYY');
                    $facts[] = [
                        'label' => __($template === NotificationTemplate::BalanceOverdue ? 'mail.common.balance_was_due' : 'mail.common.balance_due_by', [], $locale),
                        'value' => $deadline,
                    ];
                }
                // The booking page is where the balance is paid
                // (`guest.booking.pay-balance`), so the button says what it does.
                $action = [__('mail.common.pay_balance', [], $locale), $manageUrl];
                break;

            case NotificationTemplate::CharterAgreement72h:
            case NotificationTemplate::CharterAgreement24h:
                // The acceptance is a box on the passenger details form (TOK-8),
                // due with the details — not on the day of the charter, which
                // is what this message used to say.
                $by = self::detailsDeadline($booking, $starts);
                $deadline = $at($by, 'dddd D MMMM YYYY, HH:mm');

                // The 24-hour reminder can fall after that deadline; a date in
                // the past is not a deadline, so it is left out.
                if (Carbon::instance($by)->isFuture()) {
                    $facts[] = ['label' => __('mail.common.charter_by', [], $locale), 'value' => $deadline];
                }

                if ($formUrl !== null) {
                    $action = [__('mail.common.charter_button', [], $locale), $formUrl];
                }
                break;

            case NotificationTemplate::WeatherChoiceRequested:
            case NotificationTemplate::WeatherChoiceReminder:
                $owed = isset($extra['entitlement_cents'])
                    ? (int) $extra['entitlement_cents']
                    : self::weatherEntitlement($booking);
                $due = $extra['due_at'] ?? $booking->weather_choice_due_at;

                if ($owed > 0) {
                    $facts[] = ['label' => __('mail.common.weather_amount', [], $locale), 'value' => $euros($owed)];
                }
                if ($due instanceof CarbonInterface) {
                    $deadline = $at($due, 'dddd D MMMM YYYY, HH:mm');
                    $facts[] = ['label' => __('mail.common.weather_by', [], $locale), 'value' => $deadline];
                }
                // The choice is made on the booking page
                // (`guest.booking.weather-choice`).
                $action = [__('mail.common.weather_button', [], $locale), $manageUrl];
                break;

            case NotificationTemplate::WeatherChoiceApplied:
                $choice = $extra['choice'] ?? $booking->weather_choice;
                $choice = $choice instanceof WeatherChoice ? $choice : WeatherChoice::tryFrom((string) $choice);
                $amount = isset($extra['amount_cents']) ? (int) $extra['amount_cents'] : (int) $booking->refunded_cents;

                if ($choice instanceof WeatherChoice) {
                    $facts[] = ['label' => __('mail.common.weather_done', [], $locale), 'value' => __("mail.common.weather_{$choice->value}", [], $locale)];
                }
                if ($amount > 0) {
                    $facts[] = ['label' => __('mail.common.amount', [], $locale), 'value' => $euros($amount)];
                }
                if ($choice instanceof WeatherChoice && $choice->issuesVoucher()) {
                    $voucher = self::voucherIssuedFor($booking);

                    if ($voucher instanceof Voucher) {
                        $facts[] = ['label' => __('mail.common.voucher_code', [], $locale), 'value' => $voucher->code];
                    }
                }
                break;

            case NotificationTemplate::VoucherExpiry30d:
            case NotificationTemplate::VoucherExpiry7d:
                $voucher = ($extra['voucher'] ?? null) instanceof Voucher
                    ? $extra['voucher']
                    : self::voucherIssuedFor($booking);

                // A voucher is not a booking: no trip, no day, no reference and
                // no balance from the sailing it replaced. Its own card instead.
                if ($voucher instanceof Voucher) {
                    $cardEyebrow = __('mail.common.voucher', [], $locale);
                    $cardTitle = $voucher->code;
                    $cardRows = [['label' => __('mail.common.voucher_remaining', [], $locale), 'value' => $euros((int) $voucher->remaining_cents)]];

                    if ($voucher->expires_at !== null) {
                        $deadline = $at($voucher->expires_at, 'D MMMM YYYY');
                        $cardRows[] = ['label' => __('mail.common.voucher_expires', [], $locale), 'value' => $deadline];
                    }

                    $facts = [];
                    $note = __('mail.common.voucher_how', [], $locale);
                    $action = [__('mail.common.use_voucher', [], $locale), route('guest.voucher', ['code' => $voucher->code])];
                }
                break;

            case NotificationTemplate::QuoteSent:
                $quote = ($extra['quote'] ?? null) instanceof Quote ? $extra['quote'] : self::latestQuoteOf($booking);

                if ($quote instanceof Quote) {
                    $party = $quote->lineItems()
                        ->orderBy('sort_order')
                        ->get()
                        ->map(static fn (QuoteLineItem $line): array => [
                            'label' => ($line->qty > 1 ? $line->qty . ' × ' : '') . self::label($line->getTranslations('label'), $locale),
                            'amount' => ($line->kind === QuoteLineKind::Discount ? '− ' : '') . $euros((int) $line->total_cents),
                        ])
                        ->values()
                        ->all();
                    $total = $euros((int) $quote->total_cents);
                    $deadline = $at($quote->valid_until, 'dddd D MMMM YYYY');

                    $facts = [['label' => __('mail.common.quote_valid_until', [], $locale), 'value' => $deadline]];

                    if ((int) $quote->deposit_cents > 0) {
                        $facts[] = ['label' => __('mail.common.quote_deposit', [], $locale), 'value' => $euros((int) $quote->deposit_cents)];
                    }

                    $showParty = true;
                    $action = [__('mail.common.view_quote', [], $locale), route('guest.quote', ['token' => $quote->quote_token])];
                }
                break;

            case NotificationTemplate::PaymentUnfinished:
                // The party and its price, and one button back to a fresh
                // checkout that checks the seats again (ResumeAbandonedBooking).
                $facts = [];
                $showParty = true;
                $note = __('mail.payment_unfinished.note', [], $locale);
                $action = [__('mail.payment_unfinished.button', [], $locale), route('guest.checkout', ['token' => $booking->manage_token])];
                break;

            case NotificationTemplate::ReviewRequest:
                // Only to the operator's own review page. Without one the request
                // is never sent (ReviewRequestSettings::active()); a preview
                // falls back to the booking page rather than a dead button.
                $reviewUrl = $tenant instanceof Tenant ? ReviewRequestSettings::for($tenant)->googleUrl : null;

                if ($reviewUrl !== null) {
                    $action = [__('mail.common.leave_review', [], $locale), $reviewUrl];
                }
                break;

            default:
                break;
        }

        return new self(
            full: $template->carriesWholeTrip(),
            locale: $locale,
            greeting: self::blank($booking->guest_name) ? null : __('mail.common.greeting', ['name' => self::firstName((string) $booking->guest_name)], $locale),
            trip: $trip,
            boat: $boat,
            day: $starts->isoFormat('dddd D MMMM YYYY'),
            checkIn: $checkIn,
            departure: $starts->format('H:i'),
            return: $return,
            meetingName: $port instanceof Port ? self::text($port->getTranslation('name', $locale, true)) : null,
            meetingAddress: $port instanceof Port ? self::text($port->address) : null,
            meetingInstructions: $port instanceof Port ? self::text($port->getTranslation('instructions', $locale, true)) : null,
            mapUrl: $port instanceof Port ? $port->mapsUrl() : null,
            party: $party,
            // What the code took off, on the ticket card (2026-09-18). The
            // total below is already the discounted one; without this line the
            // guest cannot tell their code was honoured, which is the one
            // thing they check after typing it.
            discount: $discountCents > 0 ? $euros($discountCents) : null,
            discountCode: $discountLabel,
            total: $total,
            paid: $template !== NotificationTemplate::QuoteSent && $paidCents > 0 ? $euros($paidCents) : null,
            balance: $showsBalance && $balanceCents > 0 ? $euros($balanceCents) : null,
            balanceDue: $showsBalance && $balanceCents > 0 && $booking->balance_due_at !== null
                ? $booking->balance_due_at->copy()->locale($locale)->isoFormat('dddd D/M')
                : null,
            refund: $refund,
            bring: $product instanceof Product ? self::lines($product->getTranslation('what_to_bring', $locale, true)) : [],
            policy: self::policy($booking, $locale),
            phone: $tenant instanceof Tenant ? self::text($tenant->phone ?? null) : null,
            email: $tenant instanceof Tenant ? self::text($tenant->email) : null,
            manageUrl: $manageUrl,
            ticketUrl: $tenant instanceof Tenant && $tenant->usesCheckIn()
                ? route('guest.ticket', ['token' => $booking->manage_token])
                : null,
            detailsUrl: $detailsPending ? $formUrl : null,
            detailsBy: $detailsPending && $booking->guest_details_deadline_at !== null
                ? $booking->guest_details_deadline_at->copy()->locale($locale)->isoFormat('D/M')
                : null,
            reviewUrl: $reviewUrl,
            operator: $operator,
            cardEyebrow: $cardEyebrow,
            cardTitle: $cardTitle,
            cardRows: $cardRows,
            facts: $facts,
            factsNote: $note,
            showParty: $showParty,
            actionLabel: $action[0],
            actionUrl: $action[1],
            deadline: $deadline,
            calendarUrl: $calendar?->downloadUrl(),
            calendarGoogleUrl: $calendar?->googleUrl(),
            boardingPasses: $template->carriesWholeTrip() ? BoardingPasses::for($booking, $tenant, $locale) : [],
        );
    }

    /**
     * BKG-15: departure minus the product's `guest_details_deadline_hours`.
     *
     * The same arithmetic `SendDueReminders` schedules the reminders by, so the
     * date a message names is the date the form actually closes. A preview
     * booking that was never saved has no UTC instant, so the local start
     * stands in.
     */
    public static function detailsDeadline(Booking $booking, ?CarbonInterface $localStart = null): CarbonInterface
    {
        $hours = $booking->product instanceof Product ? (int) $booking->product->guest_details_deadline_hours : 48;
        $departure = $booking->starts_at_utc ?? $localStart ?? now();

        return Carbon::instance($departure)->copy()->subHours(max(0, $hours));
    }

    /**
     * What of this booking's refund goes back to a card, and what the operator
     * hands back themselves (cash or a transfer), in cents.
     *
     * Read from the refund rows {@see RefundBooking}
     * wrote, open or settled; a failed or withdrawn one promises nothing.
     *
     * @return array{0: int, 1: int}
     */
    private static function refundSplit(Booking $booking): array
    {
        $rows = Payment::query()
            ->where('booking_id', $booking->getKey())
            ->where('kind', PaymentKind::Refund->value)
            ->whereIn('status', [
                PaymentStatus::Pending->value,
                PaymentStatus::Processing->value,
                PaymentStatus::Succeeded->value,
            ])
            ->get(['gateway', 'amount_cents']);

        $byCard = (int) $rows->filter(static fn (Payment $row): bool => $row->gateway->isExternal())->sum('amount_cents');
        $byHand = (int) $rows->reject(static fn (Payment $row): bool => $row->gateway->isExternal())->sum('amount_cents');

        return [$byCard, $byHand];
    }

    /** The newest voucher issued in place of this booking's money. */
    private static function voucherIssuedFor(Booking $booking): ?Voucher
    {
        if ($booking->getKey() === null) {
            return null;
        }

        return Voucher::query()
            ->where('issued_for_booking_id', $booking->getKey())
            ->latest('id')
            ->first();
    }

    private static function latestQuoteOf(Booking $booking): ?Quote
    {
        if ($booking->getKey() === null) {
            return null;
        }

        return Quote::query()
            ->where('booking_id', $booking->getKey())
            ->where('status', QuoteStatus::Sent)
            ->latest('version')
            ->first();
    }

    private static function weatherEntitlement(Booking $booking): int
    {
        try {
            return RefundEntitlement::forWeather($booking)->totalCents;
        } catch (Throwable) {
            // A preview booking with no policy snapshot: say nothing rather
            // than a wrong figure.
            return 0;
        }
    }

    /**
     * «2 × Ενήλικας», then the extras, each with its total.
     *
     * Bookings from before the breakdown carried labels and prices (imports,
     * the earliest seeds) have only a band code: the label then comes from the
     * product's band of that code, and the amount is left blank rather than
     * printed as a misleading 0,00 €.
     *
     * @param  callable(int): string  $euros
     * @return list<array{label: string, amount: string}>
     */
    private static function party(Booking $booking, string $locale, callable $euros): array
    {
        $rows = [];
        $bands = null;

        foreach ((array) $booking->pax_breakdown as $line) {
            $qty = (int) ($line['qty'] ?? 0);

            if ($qty < 1) {
                continue;
            }

            $label = $line['label'] ?? null;

            if ($label === null || $label === '' || $label === []) {
                $bands ??= $booking->product?->ageBands()->get()->keyBy('code');
                $band = $bands?->get($line['code'] ?? '');
                $label = $band !== null ? $band->getTranslations('label') : ($line['code'] ?? '');
            }

            $rows[] = [
                'label' => $qty . ' × ' . self::label($label, $locale),
                'amount' => isset($line['total_cents']) ? $euros((int) $line['total_cents']) : '',
            ];
        }

        foreach ((array) $booking->extras_snapshot as $line) {
            $qty = (int) ($line['qty'] ?? 1);

            $rows[] = [
                'label' => $qty . ' × ' . self::label($line['label'] ?? ($line['ref'] ?? ''), $locale),
                'amount' => (bool) ($line['on_request'] ?? false)
                    ? __('mail.common.on_request', [], $locale)
                    : $euros((int) ($line['total_cents'] ?? 0)),
            ];
        }

        return $rows;
    }

    /** The frozen one-line cancellation terms, in the guest's language if written. */
    private static function policy(Booking $booking, string $locale): ?string
    {
        $summary = data_get($booking->policy_snapshot, 'summary');

        if (! is_array($summary)) {
            return null;
        }

        $text = $summary[$locale] ?? collect($summary)->first(
            static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
        );

        return self::text($text);
    }

    private static function label(mixed $label, string $locale): string
    {
        if (is_array($label)) {
            $text = $label[$locale] ?? collect($label)->first(static fn (mixed $v): bool => is_string($v) && $v !== '');

            return is_string($text) ? $text : '';
        }

        return (string) $label;
    }

    /** @return list<string> */
    private static function lines(mixed $value): array
    {
        return array_values(array_filter(
            array_map(static fn (mixed $line): string => is_string($line) ? trim($line) : '', (array) $value),
            static fn (string $line): bool => $line !== '',
        ));
    }

    private static function firstName(string $name): string
    {
        return (string) strtok(trim($name), ' ');
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function blank(mixed $value): bool
    {
        return ! is_string($value) || trim($value) === '';
    }
}
