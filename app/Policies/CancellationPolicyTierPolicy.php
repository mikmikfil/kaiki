<?php

declare(strict_types=1);

namespace App\Policies;

use App\Support\Authorization\Capability;

/**
 * A tier is never authorised on its own — it is edited as part of its policy's
 * repeater — but TEN-5's sibling rule for policies applies: every tenant-owned
 * model has one, or `PolicyCoverageTest` fails. Filament allows an action when
 * nothing forbids it, so a model reachable through a relation manager or a bulk
 * action with no policy is writable by every role and nothing in the code says
 * so.
 *
 * Same capability as the policy it belongs to, so the two cannot drift.
 */
class CancellationPolicyTierPolicy extends TenantOwnedPolicy
{
    protected function viewCapability(): Capability
    {
        return Capability::ManagePricing;
    }

    protected function manageCapability(): Capability
    {
        return Capability::ManagePricing;
    }
}
