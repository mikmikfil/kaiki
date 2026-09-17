<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * The messages this product sends (spec BKG-13, BKG-15, BKG-16, NTF-7).
 *
 * ## A fixed list, because the dedupe key is a template name
 *
 * BKG-16 makes every reminder *"idempotent per booking per reminder type"*, and
 * the type is this string. A free-text template name would make the dedupe read
 * depend on nobody ever writing `guest_details_48h` where the last person wrote
 * `guest_details_reminder_48h` — and the failure mode is a guest receiving the
 * same reminder twice, which is exactly the thing the index exists to prevent.
 *
 * ## Nothing here is marketing, and that is a requirement
 *
 * NTF-7: *"Guests receive no marketing email from the platform."* Every case
 * below is transactional — it exists because a guest needs to do something or
 * be somewhere. Adding a case that is not is a change to the product's promise,
 * not a change to an enum.
 *
 * ## The two halves read differently on purpose
 *
 * The `*_reminder_*` cases carry their offset in the name because that offset
 * **is** the identity: the 48-hour and 24-hour guest-details reminders are two
 * different messages with two different dedupe keys, and collapsing them would
 * send one of them twice or neither.
 */
enum NotificationTemplate: string
{
    use HasTranslatedLabel;

    // BKG-13: the moment a booking becomes real to a guest.
    case BookingConfirmed = 'booking_confirmed';
    case BookingCancelled = 'booking_cancelled';
    // An operator took people off the booking (2026-09-17).
    case BookingChanged = 'booking_changed';
    case GuestDetailsRequested = 'guest_details_requested';

    // BKG-16's five families.
    case GuestDetailsReminder48h = 'guest_details_reminder_48h';
    case GuestDetailsReminder24h = 'guest_details_reminder_24h';
    case BalanceDueReminder = 'balance_due_reminder';
    case BalanceOverdue = 'balance_overdue';
    case PreDeparture24h = 'pre_departure_24h';
    case CharterAgreement72h = 'charter_agreement_72h';
    case CharterAgreement24h = 'charter_agreement_24h';
    case VoucherExpiry30d = 'voucher_expiry_30d';
    case VoucherExpiry7d = 'voucher_expiry_7d';

    // CXL-6 and CXL-7's conversation about a cancelled sailing.
    case WeatherChoiceRequested = 'weather_choice_requested';
    case WeatherChoiceReminder = 'weather_choice_reminder';
    case WeatherChoiceApplied = 'weather_choice_applied';

    // BKG-26: the operator's offer, and its answer.
    case QuoteSent = 'quote_sent';

    /*
     * After the trip, a request for a Google review (product owner, 2026-09-17).
     *
     * NTF-7 and this case. The rule is "no marketing email", and a review
     * request sits next to that line, so the line is drawn on purpose: it goes
     * only when the operator has switched it on and given a review link
     * (App\Domain\Notifications\Support\ReviewRequestSettings), once per
     * booking, only to a guest who actually sailed — checked in or completed,
     * never cancelled or a no-show — and it is about that trip alone: no offer,
     * no discount, no other trip, no newsletter. A message that grew any of
     * those would be marketing and would not belong in this enum.
     */
    case ReviewRequest = 'review_request';

    /**
     * Is this one of BKG-16's reminders, rather than something that fires on an
     * event?
     *
     * The distinction decides who owns the idempotency. An event-driven message
     * is sent once because the event happens once; a reminder is sent once
     * because the scheduler checks the log first.
     */
    public function isReminder(): bool
    {
        return str_contains($this->value, 'reminder')
            || str_contains($this->value, 'overdue')
            || str_contains($this->value, 'pre_departure')
            || str_contains($this->value, 'charter_agreement_')
            || str_contains($this->value, 'voucher_expiry_')
            // Swept like the reminders, so the log is what keeps it to one.
            || $this === self::ReviewRequest;
    }

    /**
     * The instant this message warns about, given the booking's own.
     *
     * BKG-18's second half needs it: a reminder deferred past *the event it
     * warns about* is dropped rather than sent. A "your trip is tomorrow" SMS
     * arriving while the guest is on the boat is worse than no SMS at all.
     */
    public function warnsAbout(): bool
    {
        return $this !== self::BookingConfirmed
            && $this !== self::BookingCancelled
            && $this !== self::BookingChanged
            && $this !== self::WeatherChoiceApplied
            // Follows the trip rather than warning about it: nothing to be late for.
            && $this !== self::ReviewRequest;
    }

    /**
     * Does this message carry the whole trip, or only the few facts it is about?
     *
     * The confirmation, a change and the day-before reminder are the full
     * ticket — card, meeting point, party, what to bring, the cancellation
     * terms — and they are also the three that carry the calendar entry
     * (2026-09-18), because a calendar entry is a statement about when and
     * where a guest must be, and those are the only three messages that make
     * one. A balance reminder that added a trip to somebody's calendar would be
     * adding it for the second time.
     *
     * On the enum rather than in the mail templates, so the HTML half, the
     * plain-text half and {@see \App\Mail\GuestMail}'s attachment cannot reach
     * three different conclusions about the same message.
     */
    public function carriesWholeTrip(): bool
    {
        return match ($this) {
            self::BookingConfirmed,
            self::BookingChanged,
            self::PreDeparture24h => true,
            default => false,
        };
    }

    /** Does this message go by SMS as well as email (BKG-16's channel column)? */
    public function usesSms(): bool
    {
        return match ($this) {
            self::GuestDetailsReminder24h,
            self::BalanceDueReminder,
            self::BalanceOverdue,
            self::PreDeparture24h,
            self::BookingConfirmed => true,
            // Email only. A ναυλοσύμφωνο is a document and a voucher expiry is
            // not urgent enough to be worth a text message at the operator's
            // expense — BKG-16's own table says so for both.
            default => false,
        };
    }
}
