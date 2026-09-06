<?php

declare(strict_types=1);

namespace App\Policies;

use App\Support\Authorization\Capability;

/**
 * Extras bought on a booking (TEN-8: owner and manager manage, crew read).
 *
 * The same shape as the booking they hang off, because they are part of it: a
 * crew member reading a pax list needs to know somebody paid for the snorkel
 * kit, and marking an on-request extra fulfilled is a commercial decision with
 * a price attached.
 */
class BookingExtraPolicy extends TenantOwnedPolicy
{
    protected function viewCapability(): Capability
    {
        return Capability::ViewPaxList;
    }

    protected function manageCapability(): Capability
    {
        return Capability::ManageBookings;
    }
}
