<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * How an operator chose to settle a refund (spec CXL-5).
 *
 * CXL-5 is FIXED and lists three overrides: *"change the percentage, issue a
 * voucher instead of cash, or waive"*. The percentage is a number and lives on
 * the override itself; these are the other two, plus the ordinary case.
 *
 * `Waived` is not the same as a 0% override, and keeping them apart is the
 * point of having the case at all: 0% says *the policy gave nothing*, and
 * waived says *the policy gave something and the operator kept it*. One is a
 * term the guest agreed to and the other is a decision somebody made, and only
 * the second needs to be defensible in a dispute.
 */
enum RefundMethod: string
{
    use HasTranslatedLabel;

    /** Money back through the gateway that took it. */
    case Cash = 'cash';

    /** Credit instead, valid for `force_majeure_voucher_months` (CXL-8). */
    case Voucher = 'voucher';

    /** Nothing back, by operator decision — never by policy. */
    case Waived = 'waived';
}
