<?php

declare(strict_types=1);

namespace App\Policies;

use App\Support\Authorization\Capability;

/**
 * Which trip is which product on an OTA (TEN-8, ADR-0034).
 *
 * `ManageCatalogue`, the same capability as {@see IcalSourcePolicy} and for a
 * related reason: this row decides **which of the operator's trips a third
 * party is allowed to sell**. Getting it wrong sells the wrong boat — the id
 * on GetYourGuide's side says "sunset cruise" and ours points at the morning
 * transfer — so it belongs with the people who look after the catalogue, not
 * with whoever happens to hold a login.
 *
 * Crew do not have it. A skipper with a phone has no business deciding what is
 * listed on a marketplace, and the capability they do hold stops at the
 * passenger list.
 */
class ChannelProductMapPolicy extends TenantOwnedPolicy
{
    protected function viewCapability(): Capability
    {
        return Capability::ManageCatalogue;
    }

    protected function manageCapability(): Capability
    {
        return Capability::ManageCatalogue;
    }
}
