<?php

declare(strict_types=1);

namespace App\Domain\Booking\Data;

use InvalidArgumentException;
use Spatie\LaravelData\Data;

/**
 * What an operator changed about a manual booking's price, and why (BKG-31).
 *
 * > The operator may apply a `discount_cents` **with a reason** and may
 * > override the total, **both recorded in the price snapshot as operator
 * > adjustments**.
 *
 * ## The reason is enforced by the constructor
 *
 * The third override in the product to work this way, after {@see RefundOverride}
 * (CXL-5) and {@see CheckInOverride} (BKG-22), and for the same argument: a
 * validation rule on a Filament field binds the one screen that has the field,
 * and the API, the importer and the console command all skip it. Refusing to
 * *construct* the adjustment without a reason binds every caller.
 *
 * ## Recorded in the snapshot, not folded into the total
 *
 * BKG-31's own wording. A manual booking whose `total_cents` simply differs
 * from what the engine computed is a booking nobody can explain a year later —
 * and the price snapshot is the document that has to explain it (§3.4). So the
 * engine's own figure survives beside the operator's, and
 * {@see self::toSnapshotLines()} writes both.
 *
 * ## A discount and a total override are different things
 *
 * A **discount** says "take €20 off this" and keeps the derivation intact: the
 * lines still add up, minus a named amount. A **total override** replaces the
 * answer outright — a negotiated price for a repeat customer, a rounded figure
 * agreed on the phone. Both are legitimate, they mean different things to an
 * accountant, and collapsing them into one field would lose which happened.
 */
final class ManualBookingAdjustment extends Data
{
    /**
     * @param  int  $discountCents  taken off the computed total; never negative
     * @param  int|null  $overrideTotalCents  replaces the total outright, or null
     * @param  string  $reason  the operator's own words; never empty
     *
     * @throws InvalidArgumentException when the reason is blank or an amount is negative
     */
    public function __construct(
        public readonly string $reason,
        public readonly int $discountCents = 0,
        public readonly ?int $overrideTotalCents = null,
    ) {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A manual price adjustment requires a reason (BKG-31).');
        }

        if ($discountCents < 0) {
            // §1.4: the sign lives in the column's meaning. A discount is an
            // amount taken off, so it is positive — a negative one would be a
            // surcharge wearing a discount's name.
            throw new InvalidArgumentException('A discount is an amount taken off, so it is never negative (§1.4).');
        }

        if ($overrideTotalCents !== null && $overrideTotalCents < 0) {
            throw new InvalidArgumentException('A booking total cannot be negative.');
        }
    }

    public function isEmpty(): bool
    {
        return $this->discountCents === 0 && $this->overrideTotalCents === null;
    }

    /** The total after this adjustment, given what the engine computed. */
    public function applyTo(int $computedTotalCents): int
    {
        if ($this->overrideTotalCents !== null) {
            return $this->overrideTotalCents;
        }

        return max(0, $computedTotalCents - $this->discountCents);
    }

    /**
     * The `operator_adjustments` block BKG-31 asks the snapshot to carry.
     *
     * Both numbers, always: the figure the engine produced and the figure the
     * operator settled on. A block holding only the second would record the
     * outcome and lose the decision, which is the same mistake
     * {@see RefundOverride} avoids by storing a percentage rather than an
     * amount.
     *
     * @return array<string, mixed>
     */
    public function toSnapshot(int $computedTotalCents): array
    {
        return [
            'reason' => $this->reason,
            'computed_total_cents' => $computedTotalCents,
            'discount_cents' => $this->discountCents,
            'override_total_cents' => $this->overrideTotalCents,
            'applied_total_cents' => $this->applyTo($computedTotalCents),
            'adjusted_at' => now()->toIso8601ZuluString(),
        ];
    }
}
