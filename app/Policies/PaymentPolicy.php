<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\Authorization\Capability;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Payments (TEN-8: `ViewFinancials` to read, `ManageBookings` to write).
 *
 * ## Crew never see these, and that is the point of a separate capability
 *
 * A pax list tells crew who is aboard. A payment row tells them what each guest
 * paid, which is not their business and is the kind of thing that ends up
 * discussed on a quay.
 *
 * ## Deletion is refused for everybody, including the owner
 *
 * `docs/data-model.md` §2.5: payments are **never deleted, never soft-deleted**.
 * A payment that can disappear is a payment an operator cannot reconcile against
 * their own bank statement, and the `restrictOnDelete` on `booking_id` is the
 * same rule pointing the other way.
 *
 * The base class would give `forceDelete` to the owner. That is right for a
 * vessel and wrong here, so both deletes are refused outright — the same
 * three-layer posture `AuditLog` takes, one layer down: no route in the panel,
 * and no policy that would allow one if somebody built it.
 */
class PaymentPolicy extends TenantOwnedPolicy
{
    protected function viewCapability(): Capability
    {
        return Capability::ViewFinancials;
    }

    protected function manageCapability(): Capability
    {
        return Capability::ManageBookings;
    }

    /** Never. See the class docblock. */
    public function delete(User $user, Model $model): Response|bool
    {
        return false;
    }

    /** Never, for anybody. */
    public function forceDelete(User $user, Model $model): Response|bool
    {
        return false;
    }
}
