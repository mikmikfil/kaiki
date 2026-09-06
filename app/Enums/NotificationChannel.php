<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * How a notification went out (`docs/data-model.md` §2.7).
 *
 * `webhook` sits beside `mail` and `sms` because an outbound webhook is a
 * delivery attempt with a recipient, a status and a provider reference like any
 * other — and an operator asking "did anything reach my system about this
 * booking" wants one timeline rather than three. The deliveries themselves are
 * M5's `webhook_deliveries`; this is the line in the booking's own history.
 */
enum NotificationChannel: string
{
    use HasTranslatedLabel;

    case Mail = 'mail';
    case Sms = 'sms';
    case Webhook = 'webhook';

    /** Does this channel cost the operator money per message? */
    public function isMetered(): bool
    {
        return $this === self::Sms;
    }
}
