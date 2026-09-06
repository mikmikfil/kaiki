<?php

declare(strict_types=1);

namespace App\Domain\Booking\Data;

use App\Domain\Booking\Support\CheckInWindow;
use InvalidArgumentException;
use Spatie\LaravelData\Data;

/**
 * Crew checking somebody in before the window opens (spec BKG-22).
 *
 * > Check-in is possible from `check_in_offset_minutes` before departure until
 * > `ends_at_utc`; **earlier check-in requires an explicit operator override
 * > which is logged**.
 *
 * ## The reason is enforced by the constructor, for CXL-5's reason
 *
 * The same argument {@see RefundOverride} makes, applied to the other override
 * in the product: a validation rule on a Filament field binds the one screen
 * that has the field, and the API and the console command skip it. Refusing to
 * **construct** the override without a reason binds every caller, and the
 * failure lands where the mistake is rather than in an audit row that says
 * `null` a month later.
 *
 * ## What it is *not* allowed to override
 *
 * The late edge. There is no flag here for checking somebody in after
 * `ends_at_utc`, because a check-in recorded after the boat came back is not an
 * early decision — it is a false manifest, and BKG-22 offers an override for
 * one edge only. {@see CheckInWindow} carries the same asymmetry.
 */
final class CheckInOverride extends Data
{
    /**
     * @param  string  $reason  the operator's own words; never empty
     *
     * @throws InvalidArgumentException when the reason is blank
     */
    public function __construct(public readonly string $reason)
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('An early check-in requires a reason (BKG-22).');
        }
    }

    /**
     * The audit context for `override.applied`.
     *
     * Scalars, no personal data — ADR-0025 §3, and the guest's name is exactly
     * the thing an early check-in row would be tempted to carry. The **minutes
     * early** is the number worth keeping: "four minutes" and "four hours" are
     * different decisions, and neither is legible from a timestamp pair
     * somebody has to subtract by hand a year later.
     *
     * @return array<string, scalar|null>
     */
    public function auditContext(int $minutesEarly): array
    {
        return [
            'kind' => 'check_in_early',
            'minutes_early' => $minutesEarly,
        ];
    }
}
