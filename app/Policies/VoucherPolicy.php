<?php

declare(strict_types=1);

namespace App\Policies;

use App\Support\Authorization\Capability;

/**
 * Vouchers (TEN-8: owner and manager).
 *
 * `ViewFinancials` to read and `ManageBookings` to write, and the split is not
 * cosmetic: issuing a voucher gives away the operator's money, so it sits with
 * the capability that already covers cancelling and refunding. Reading one is a
 * financial question — "how much credit is outstanding" — and belongs with the
 * rest of the money screens.
 *
 * Crew have neither, deliberately. A voucher code is a bearer instrument in
 * everything but name.
 */
class VoucherPolicy extends TenantOwnedPolicy
{
    protected function viewCapability(): Capability
    {
        return Capability::ViewFinancials;
    }

    protected function manageCapability(): Capability
    {
        return Capability::ManageBookings;
    }
}
