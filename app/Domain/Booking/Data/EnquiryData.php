<?php

declare(strict_types=1);

namespace App\Domain\Booking\Data;

use App\Enums\BookingSource;
use Spatie\LaravelData\Data;

/**
 * A question, as it arrives (spec BKG-28, `docs/api.md` `EnquiryCreateRequest`).
 *
 * ## There is no honeypot property, deliberately
 *
 * `company_website` is validated and refused by the form request and never
 * reaches this object. §2.5: *"a honeypot field that is never persisted."*
 * Carrying it here — even to discard it — would put it one careless
 * `->toArray()` away from a column, and a stored honeypot value is a column
 * nobody remembers the purpose of.
 *
 * Neither is `form_rendered_at` here. The timing check is a **decision about
 * the request**, made where the request is; what survives it is an enquiry.
 */
final class EnquiryData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly string $message,
        public readonly string $locale = 'el',
        public readonly ?string $productUuid = null,
        public readonly ?string $phone = null,
        /** A local date with no timezone semantics (§1.5). */
        public readonly ?string $preferredDate = null,
        public readonly ?int $pax = null,
        public readonly BookingSource $source = BookingSource::Widget,
        public readonly ?string $ipAddress = null,
        public readonly ?string $userAgent = null,
    ) {}
}
