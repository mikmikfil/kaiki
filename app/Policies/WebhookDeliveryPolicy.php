<?php

declare(strict_types=1);

namespace App\Policies;

use App\Support\Authorization\Capability;

/**
 * Webhook deliveries — the same permission as the endpoint they belong to.
 *
 * A delivery's stored payload is a copy of a booking: guest name, email, the
 * money. Reading the history is therefore reading booking data through a second
 * door, and it should not be a wider door than the first.
 *
 * Resending is a write, and it is the reason `manageCapability` is not the
 * gentler `ViewDepartures`: a resend puts a guest's details on somebody else's
 * server again.
 */
class WebhookDeliveryPolicy extends TenantOwnedPolicy
{
    protected function viewCapability(): Capability
    {
        return Capability::ManageApiKeys;
    }

    protected function manageCapability(): Capability
    {
        return Capability::ManageApiKeys;
    }
}
