<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

/**
 * A guest has a decision to make about money (spec CXL-6, CXL-7).
 *
 * Dispatched once per booking on a weather-cancelled departure — **per
 * booking**, not per departure, because the entitlement comes from each
 * booking's own frozen snapshot (CXL-6) and two guests on the same sailing can
 * be owed different proportions of what they paid.
 *
 * `dueAt` travels with it because the email has to state the deadline: CXL-7
 * applies the operator's default at fourteen days, and a guest who was never
 * told that is a guest who was defaulted on without notice.
 *
 * **Nothing listens yet.** The email is #87; the page the link points at is
 * #86.
 */
final class WeatherChoiceRequested
{
    use Dispatchable;

    public function __construct(
        public readonly int $bookingId,
        public readonly int $tenantId,
        public readonly int $entitlementCents,
        public readonly Carbon $dueAt,
    ) {}
}
