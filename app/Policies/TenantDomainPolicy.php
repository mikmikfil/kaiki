<?php

declare(strict_types=1);

namespace App\Policies;

use App\Support\Authorization\Capability;

/**
 * Custom domains — part of how the operator's brand appears to guests, so it
 * sits with branding rather than with billing.
 */
class TenantDomainPolicy extends TenantOwnedPolicy
{
    protected function viewCapability(): Capability
    {
        return Capability::ManageBranding;
    }

    protected function manageCapability(): Capability
    {
        return Capability::ManageBranding;
    }
}
