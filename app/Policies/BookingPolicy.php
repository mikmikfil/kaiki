<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\Authorization\Capability;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Bookings (TEN-8: owner and manager manage, crew read).
 *
 * ## Crew can read and cannot write, and that asymmetry is the whole policy
 *
 * TEN-8 gives crew `ViewPaxList` — somebody standing on the quay needs to know
 * who is aboard. It does not give them `ManageBookings`, because cancelling a
 * booking moves money, and the person holding the ropes is not the person who
 * answers for a refund.
 *
 * So this overrides {@see TenantOwnedPolicy::view()} rather than using one
 * capability for both: the base class assumes reading and writing are the same
 * permission, which is right for a catalogue and wrong for a booking.
 *
 * ## Deletion is a status, never a row disappearing
 *
 * A booking is soft-deleted at most, and `forceDelete` stays with the owner
 * through the base class. Tax law outranks tidiness: `payments` and `invoices`
 * point at this row with `restrictOnDelete`, and a GDPR erasure tombstones the
 * guest columns rather than removing the record (§2.7's `gdpr_requests` note).
 */
class BookingPolicy extends TenantOwnedPolicy
{
    protected function viewCapability(): Capability
    {
        return Capability::ViewPaxList;
    }

    protected function manageCapability(): Capability
    {
        return Capability::ManageBookings;
    }

    /**
     * Crew see the list; only owner and manager change anything on it.
     *
     * Spelled out rather than inherited so that the difference is visible in
     * the file somebody opens when they ask "why can crew see this".
     */
    public function view(User $user, Model $model): bool
    {
        return $user->hasCapability(Capability::ViewPaxList) && $this->sameTenant($user, $model);
    }

    /**
     * Check-in is its own capability, and crew have it.
     *
     * TEN-8 lists `CheckInGuests` beside `ViewPaxList` for exactly this: the
     * person scanning tickets at the gangway is doing the one write crew are
     * meant to do, and routing it through `ManageBookings` would either lock
     * them out of their job or hand them the refund button.
     */
    public function checkIn(User $user, Model $model): Response|bool
    {
        return $this->refusalWhenReadOnly()
            ?? ($user->hasCapability(Capability::CheckInGuests) && $this->sameTenant($user, $model));
    }
}
