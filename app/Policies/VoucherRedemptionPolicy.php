<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\Authorization\Capability;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * The voucher ledger (TEN-8: `ViewFinancials` to read, `ManageBookings` to write).
 *
 * ## Deletion is refused, because the ledger is the balance
 *
 * PRC-19.4 requires `vouchers.remaining_cents` to be reconstructible from these
 * rows at all times. Deleting one does not merely lose a record — it silently
 * *changes the voucher's balance*, and in the direction that gives an operator's
 * money away. A cancellation writes a reversal onto the row; it never removes
 * one.
 *
 * The base class hands `forceDelete` to the owner, which is right for a vessel
 * and wrong for a ledger, so both are refused here.
 */
class VoucherRedemptionPolicy extends TenantOwnedPolicy
{
    protected function viewCapability(): Capability
    {
        return Capability::ViewFinancials;
    }

    protected function manageCapability(): Capability
    {
        return Capability::ManageBookings;
    }

    public function delete(User $user, Model $model): Response|bool
    {
        return false;
    }

    public function forceDelete(User $user, Model $model): Response|bool
    {
        return false;
    }
}
