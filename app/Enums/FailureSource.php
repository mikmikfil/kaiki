<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Where a row in OPS-21's failure feed came from.
 *
 * ## The feed is a view, not a table
 *
 * There is no `operator_failures` table and there should not be. Every source
 * below already records its own failure — with its own retry semantics, its own
 * error text and its own idea of what "try again" means — and copying that into
 * a seventh table would produce two records of the same event that drift the
 * first time one of them is updated and the other is not.
 *
 * So this enum is what lets a heterogeneous list stay honest: each row knows
 * which table it came from, and the retry button dispatches to that table's own
 * Action rather than to a generic one that would have to guess.
 *
 * ## OPS-21 names six kinds; four of them exist
 *
 * *"payment, myDATA, SMS, iCal, webhook, PDF"*. **myDATA is M6** and has no
 * model yet. **PDF** failures — a Browsershot timeout generating an e-ticket —
 * land in `failed_jobs` with no operator-facing row, and giving them one is a
 * change to `GenerateETicket` rather than to this feed.
 *
 * Both absences are named here rather than left as a gap somebody rediscovers
 * when the feed looks thin.
 */
enum FailureSource: string
{
    use HasTranslatedLabel;

    /** An email or SMS that did not arrive (`notification_logs`). */
    case Notification = 'notification';

    /** A charge the gateway refused (`payments`). */
    case Payment = 'payment';

    /** A gateway telling us something we could not process (`gateway_webhook_events`). */
    case GatewayWebhook = 'gateway_webhook';

    /** Somebody else's calendar we can no longer read (`ical_sources`). */
    case IcalSync = 'ical_sync';

    /** A CSV that did not build (`export_jobs`). */
    case Export = 'export';

    /** A webhook of ours that never arrived (`webhook_deliveries`). */
    case OutboundWebhook = 'outbound_webhook';

    /**
     * Can a row from this source be tried again from the feed?
     *
     * Three of the six can. The other three are failures of somebody else's
     * side that no button here can repair:
     *
     * - a **payment** is refused by a bank, and retrying it is the guest's
     *   action on their own card, not the operator's on their behalf;
     * - a **gateway webhook** we could not process is a message we already have
     *   — it needs a person to look at why, and PAY-7 deliberately keeps an
     *   orphan visible rather than retried;
     * - an **iCal** source retries itself every quarter of an hour, so a button
     *   would promise something the schedule is already doing.
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::Notification, self::Export, self::OutboundWebhook => true,
            self::Payment, self::GatewayWebhook, self::IcalSync => false,
        };
    }

    /** The Filament badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::Payment => 'danger',
            self::Notification, self::OutboundWebhook => 'warning',
            self::GatewayWebhook => 'danger',
            self::IcalSync, self::Export => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Notification => 'heroicon-o-envelope',
            self::Payment => 'heroicon-o-credit-card',
            self::GatewayWebhook => 'heroicon-o-arrow-down-on-square',
            self::IcalSync => 'heroicon-o-calendar-days',
            self::Export => 'heroicon-o-arrow-down-tray',
            self::OutboundWebhook => 'heroicon-o-bolt',
        };
    }
}
