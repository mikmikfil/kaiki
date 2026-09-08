<?php

declare(strict_types=1);

namespace App\Policies;

use App\Support\Authorization\Capability;

/**
 * Webhook endpoints (TEN-8: owner only, beside the API keys).
 *
 * A webhook endpoint decides where a booking's guest name, email and money go
 * next, and its signing secret is what makes the receiver believe them. That is
 * the same class of credential as an API key — reach outside the platform,
 * outside the panel's audit trail — so it sits with billing and gateway
 * credentials rather than with the day-to-day catalogue work.
 *
 * `ManageApiKeys` rather than a capability of its own: TEN-8's matrix is a
 * fixed list, and inventing a case for one screen would put a role in the
 * product that nobody asked for and that `RoleMatrixTest` would then have to
 * learn.
 */
class WebhookEndpointPolicy extends TenantOwnedPolicy
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
