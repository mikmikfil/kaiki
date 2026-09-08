<?php

declare(strict_types=1);

namespace App\Enums;

use App\Domain\Webhooks\EventRegistry;
use App\Enums\Concerns\HasTranslatedLabel;

/**
 * The four things Kaiki will tell somebody else's system about (spec OPS-19).
 *
 * ## Four, and the shortness is the design
 *
 * `docs/api.md` §8.1 fixes the list at exactly these, and every one of them is a
 * thing an operator's accountant, spreadsheet or CRM has a reason to act on:
 * money arrived, money is going back, a boat is not sailing, a manifest is
 * ready. The events this codebase *could* emit are far more numerous — there are
 * eighteen classes in `app/Events/` — and publishing them would turn an internal
 * vocabulary into a public contract that can never change again.
 *
 * A fifth event is additive and cheap. A fifth event withdrawn because it was a
 * bad idea breaks every consumer that subscribed to it.
 *
 * ## The values contain dots, and that is why `line()` exists
 *
 * `HasTranslatedLabel::line()` fetches the enum's whole lang block and indexes
 * it, rather than asking for `enums.webhook_event.booking.confirmed.label` —
 * which Laravel would read as a four-level path and answer with the key. The
 * trait's own docblock records that this bug shipped once already.
 *
 * @see EventRegistry for what an operator is allowed to store against an endpoint
 */
enum WebhookEvent: string
{
    use HasTranslatedLabel;

    /** Reached `confirmed` — a gateway webhook, or an operator marking a manual booking paid. */
    case BookingConfirmed = 'booking.confirmed';

    /** Reached `cancelled` — by the guest, the operator, or a cascade from a cancelled departure. */
    case BookingCancelled = 'booking.cancelled';

    /** Weather, operator, `min_pax`, or a private charter taking the vessel. */
    case DepartureCancelled = 'departure.cancelled';

    /** Every passenger has the fields this operator requires. Carries counts, never documents. */
    case GuestDetailsCompleted = 'guest_details.completed';

    /** @return list<string> */
    public static function names(): array
    {
        return array_map(static fn (self $event): string => $event->value, self::cases());
    }
}
