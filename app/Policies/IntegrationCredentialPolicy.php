<?php

declare(strict_types=1);

namespace App\Policies;

use App\Support\Authorization\Capability;

/**
 * Gateway and provider credentials (TEN-8: owner only).
 *
 * The same reasoning as {@see ApiKeyPolicy}, one step further. An API key can
 * read an operator's bookings; these credentials can take an operator's money
 * and issue invoices in their tax name. A manager runs the business day to day
 * and does not need either, so both sit with billing and staff management
 * rather than with the catalogue.
 *
 * View and manage are the **same** capability on purpose. There is no useful
 * read-only view of a credential — the secret half is never rendered, so
 * "viewing" one means seeing which provider is configured and when it last
 * verified. Splitting the two would create a role that can watch an
 * integration fail and do nothing about it.
 */
class IntegrationCredentialPolicy extends TenantOwnedPolicy
{
    protected function viewCapability(): Capability
    {
        return Capability::ManageGatewayCredentials;
    }

    protected function manageCapability(): Capability
    {
        return Capability::ManageGatewayCredentials;
    }
}
