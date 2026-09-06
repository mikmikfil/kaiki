<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\Authorization\Capability;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Manifest rows (TEN-8, ADR-0012, SEC-14).
 *
 * ## Three capabilities, because this row holds three different things
 *
 * A `booking_guests` row carries a name, a check-in state and a passport
 * number, and those are not the same secret:
 *
 * - **`ViewPaxList`** — the name and the seat. Crew have it; it is the list
 *   they read at the gangway.
 * - **`CheckInGuests`** — ticking somebody aboard. Crew have this too, and it
 *   is the only write they are meant to do.
 * - **`ViewGuestDocuments`** — the passport number. Crew do **not** have it,
 *   and that is the point of a separate capability: a document number is
 *   `encrypted`, never indexed and purged after the retention window
 *   (ADR-0012), and the person scanning tickets on a pier has no reason to see
 *   one.
 *
 * Collapsing them would mean either crew cannot do their job or a phone left on
 * a bench shows a stranger's passport.
 */
class BookingGuestPolicy extends TenantOwnedPolicy
{
    protected function viewCapability(): Capability
    {
        return Capability::ViewPaxList;
    }

    protected function manageCapability(): Capability
    {
        return Capability::ManageBookings;
    }

    /** The passport number, and nothing else on the row. */
    public function viewDocument(User $user, Model $model): bool
    {
        return $user->hasCapability(Capability::ViewGuestDocuments) && $this->sameTenant($user, $model);
    }

    /** Crew's one write: ticking a guest aboard. */
    public function checkIn(User $user, Model $model): Response|bool
    {
        return $this->refusalWhenReadOnly()
            ?? ($user->hasCapability(Capability::CheckInGuests) && $this->sameTenant($user, $model));
    }
}
