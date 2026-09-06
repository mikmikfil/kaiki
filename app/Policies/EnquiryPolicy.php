<?php

declare(strict_types=1);

namespace App\Policies;

use App\Support\Authorization\Capability;

/**
 * The enquiry inbox (TEN-8: `ManageBookings`).
 *
 * ## Crew do not read these, and the reason is not secrecy
 *
 * An enquiry is a stranger's message with their name, email and phone in it,
 * addressed to whoever answers the operator's post. Crew have `ViewPaxList` so
 * they know who is aboard today; a person who has not booked anything is not on
 * that list and is not their business.
 *
 * `ManageBookings` for both reading and writing, rather than the base class's
 * usual split, because there is no meaningful "read the inbox but do not touch
 * it" role — answering is the job.
 */
class EnquiryPolicy extends TenantOwnedPolicy
{
    protected function viewCapability(): Capability
    {
        return Capability::ManageBookings;
    }

    protected function manageCapability(): Capability
    {
        return Capability::ManageBookings;
    }
}
