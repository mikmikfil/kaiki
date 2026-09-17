<?php

declare(strict_types=1);

namespace App\Policies;

use App\Support\Authorization\Capability;

/**
 * Answers to a trip's checkout questions (TEN-8): the same shape as the booking
 * extras beside them. Crew read them on the boarding list — «Μεταφορά: Ναι» is
 * something the quay needs to know — and changing one is managing the booking.
 */
class BookingAnswerPolicy extends TenantOwnedPolicy
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
