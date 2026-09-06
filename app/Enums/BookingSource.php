<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Where a booking came from (`docs/data-model.md` §2.5).
 *
 * Not decoration. Two of these change behaviour outright: `manual` bookings may
 * bypass lead-time and advance limits but never legal capacity (BKG-32), and
 * `import` bookings are created already `confirmed` and MUST NOT trigger
 * confirmation notifications, invoices or webhooks (BKG-34, SAA-15) — emailing
 * a year of historical guests "your trip is confirmed" is the classic
 * migration-day disaster.
 *
 * Shared with `enquiries`, which uses the same vocabulary.
 */
enum BookingSource: string
{
    use HasTranslatedLabel;

    case Widget = 'widget';
    case Hosted = 'hosted';
    case Wordpress = 'wordpress';
    case Manual = 'manual';
    case Import = 'import';

    /** Did a guest make this themselves? */
    public function isGuestInitiated(): bool
    {
        return match ($this) {
            self::Widget, self::Hosted, self::Wordpress => true,
            self::Manual, self::Import => false,
        };
    }

    /** BKG-34: an imported booking notifies nobody. */
    public function notifiesGuest(): bool
    {
        return $this !== self::Import;
    }
}
