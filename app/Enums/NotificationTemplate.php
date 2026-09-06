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
            || str_contains($this->value, 'voucher_expiry_');
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
            && $this !== self::WeatherChoiceApplied;
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
