<?php

declare(strict_types=1);

namespace App\Policies;

use App\Support\Authorization\Capability;

/**
 * The operator's home page (TEN-8: owner and manager, never crew).
 *
 * `ManageBranding` rather than `ManageCatalogue`, and the distinction is not
 * cosmetic. A block does not change what is for sale, what it costs or whether
 * a boat can sail — it changes what the business says about itself in public.
 * That is the same job as the logo and the colours, and it belongs to whoever
 * the operator trusts with those.
 *
 * The practical consequence is that a manager who may add a product may also
 * rewrite the front page, which is right for a small operator where the same
 * person does both. Crew reach neither.
 */
class HomePageBlockPolicy extends TenantOwnedPolicy
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
