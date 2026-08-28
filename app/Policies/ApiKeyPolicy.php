<?php

declare(strict_types=1);

namespace App\Policies;

use App\Support\Authorization\Capability;

/**
 * API keys (TEN-8: owner only).
 *
 * A secret key can read every booking and every guest's personal data through
 * the API. That is the same reach as the panel itself, without the panel's
 * audit trail, so it sits alongside billing and gateway credentials rather than
 * with the day-to-day catalogue work.
 */
class ApiKeyPolicy extends TenantOwnedPolicy
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
