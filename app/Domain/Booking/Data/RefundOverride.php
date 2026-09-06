<?php

declare(strict_types=1);

namespace App\Domain\Booking\Data;

use App\Enums\RefundMethod;
use InvalidArgumentException;
use Spatie\LaravelData\Data;

/**
 * An operator overruling the policy, and saying why (spec CXL-5, FIXED).
 *
 * ## The reason is enforced by the constructor, not by a form
 *
 * CXL-5: *"An override requires a reason, which is stored and shown in the
 * booking timeline."* A validation rule on a Filament field satisfies that for
 * the one screen that has the field, and the API, the console command and the
 * next screen all get to skip it. Refusing to *construct* an override without
 * a reason means every caller is bound by the same rule, and the failure is at
 * the point of the mistake rather than three layers down in an audit row that
 * quietly says `null`.
 *
 * ## Why a percentage and a method rather than an amount
 *
 * An amount would be simpler and would lose the thing that matters. An
 * operator refunding "half" of a booking that later turns out to have had a
 * different `paid_cents` than anybody thought has made a decision about a
 * *proportion*; recording 4 000 cents records the arithmetic and throws away
 * the decision. The percentage is also what the audit row can be read against
 * years later, when the payment rows have been reconciled a dozen times.
 *
 * A null percentage means *"the policy's own figure"* — the operator is
 * overriding the **method** only, which is CXL-5's "issue a voucher instead of
 * cash" with the amount left alone.
 */
final class RefundOverride extends Data
{
    /**
     * @param  int|null  $percent  0–100, or null to keep the policy's own figure
     * @param  string  $reason  the operator's own words; never empty
     *
     * @throws InvalidArgumentException when the reason is blank or the
     *                                  percentage is outside 0–100
     */
    public function __construct(
        public readonly RefundMethod $method,
        public readonly string $reason,
        public readonly ?int $percent = null,
    ) {
        if (trim($reason) === '') {
            // CXL-5, at the only place that can enforce it for every caller.
            throw new InvalidArgumentException('A refund override requires a reason (CXL-5).');
        }

        if ($percent !== null && ($percent < 0 || $percent > 100)) {
            throw new InvalidArgumentException('A refund override percentage must be between 0 and 100.');
        }
    }

    /** Waiving is the one method whose percentage is not the operator's to pick. */
    public static function waive(string $reason): self
    {
        return new self(method: RefundMethod::Waived, reason: $reason, percent: 0);
    }

    /**
     * The percentage this override implies, given what the policy said.
     *
     * `Waived` is always zero however it was constructed — an override that
     * said "waive" and "60%" is contradictory, and the word wins over the
     * number because the word is what the operator meant.
     */
    public function percentAgainst(int $policyPercent): int
    {
        if ($this->method === RefundMethod::Waived) {
            return 0;
        }

        return $this->percent ?? $policyPercent;
    }

    /**
     * The audit context for `override.applied`.
     *
     * Scalars only, and no personal data — ADR-0025 §3, enforced by
     * `NoPersonalDataInAuditContextTest`. The reason travels in its own column
     * rather than in here, which is what lets that scanner stay strict.
     *
     * @return array<string, scalar|null>
     */
    public function auditContext(int $policyPercent): array
    {
        return [
            'method' => $this->method->value,
            'policy_percent' => $policyPercent,
            'applied_percent' => $this->percentAgainst($policyPercent),
        ];
    }
}
