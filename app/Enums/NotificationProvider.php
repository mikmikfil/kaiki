<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Who carried the message (`docs/data-model.md` §2.7, spec NTF-1, NTF-2).
 *
 * ## `null_gateway` is a provider, not an absence
 *
 * NTF-2 is explicit that the platform provides a **fallback**, and recording it
 * as a real provider is what makes the difference visible: a tenant with no SMS
 * configured produces rows saying `null_gateway`, and an operator looking at
 * their own log can see that their reminders are being composed and dropped
 * rather than silently never attempted.
 *
 * A null value in the column would look identical to a bug.
 */
enum NotificationProvider: string
{
    use HasTranslatedLabel;

    case Postmark = 'postmark';
    case Apifon = 'apifon';
    case Twilio = 'twilio';

    /** Composes and records, sends nothing. See the class docblock. */
    case NullGateway = 'null_gateway';

    /** Does a message through this provider actually leave the building? */
    public function delivers(): bool
    {
        return $this !== self::NullGateway;
    }
}
