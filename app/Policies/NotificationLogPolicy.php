<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\Authorization\Capability;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * The notification log (TEN-8, spec NTF-3, BKG-14).
 *
 * ## Read and retry are the same permission, and it is `ManageBookings`
 *
 * BKG-14 puts a **retry button** beside a failed listener, so the person who
 * can read this feed is the person who can act on it — a read-only view of
 * messages nobody can resend is a list of things going wrong.
 *
 * Crew do not have it. A log row carries a guest's email address and phone
 * number in the `to` column, which is the same reasoning `EnquiryPolicy`
 * gives: crew know who is aboard today, and a stranger's contact details are
 * not part of that.
 *
 * ## Deletion is refused for everybody
 *
 * §2.7: pruned at twelve months by a scheduled job, and never by hand. A log
 * an operator can tidy is a log that cannot answer *"did the guest ever get the
 * confirmation"* — which is the only question it exists to answer. The base
 * class would give `forceDelete` to the owner; both are refused outright, the
 * same three-layer posture `AuditLog` and `Payment` take.
 */
class NotificationLogPolicy extends TenantOwnedPolicy
{
    protected function viewCapability(): Capability
    {
        return Capability::ManageBookings;
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
