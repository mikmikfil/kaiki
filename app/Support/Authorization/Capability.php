<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Enums\Role;

/**
 * The TEN-8 role matrix, in one place.
 *
 * Three fixed roles with hardcoded abilities, **not** `spatie/laravel-permission`
 * — that package is outside spec §3.2 and only conditionally approved
 * (ARC-21a, ADR-0019). Installing it pre-emptively is a hard stop; if the three
 * roles ever prove insufficient, that is an ADR stating which capability could
 * not be expressed, not a refactor.
 *
 * Capabilities are named after what an operator would call the thing, so a
 * policy reads like the sentence in the spec.
 */
enum Capability: string
{
    // Owner only (TEN-8).
    case ManageBilling = 'manage_billing';
    case ManageApiKeys = 'manage_api_keys';
    case ManageGatewayCredentials = 'manage_gateway_credentials';
    case ManageStaff = 'manage_staff';
    case DeleteTenant = 'delete_tenant';

    // Owner and manager: the day-to-day business.
    case ManageCatalogue = 'manage_catalogue';
    case ManagePricing = 'manage_pricing';
    case ManageBookings = 'manage_bookings';
    case ViewFinancials = 'view_financials';
    case ViewGuestDocuments = 'view_guest_documents';
    case ManageBranding = 'manage_branding';
    case ExportData = 'export_data';

    /**
     * Read the operator's own audit trail (ADR-0025 §4, SEC-16).
     *
     * Its own capability rather than a side effect of `ManageStaff` or
     * `ViewFinancials`, because the ADR asks for *"who can read the trail"* to
     * be one line in this matrix. A trail readable as a consequence of some
     * other permission is a trail whose audience changes the next time that
     * permission is widened.
     */
    case ViewAuditLog = 'view_audit_log';

    // Crew as well, within the departure window.
    case ViewDepartures = 'view_departures';
    case ViewPaxList = 'view_pax_list';
    case CheckInGuests = 'check_in_guests';
    case ViewManifest = 'view_manifest';

    /**
     * Roles holding this capability.
     *
     * @return list<Role>
     */
    public function heldBy(): array
    {
        return match ($this) {
            // Billing, keys, credentials, staff and deletion are the owner's
            // alone. A manager runs the business; only the owner can end it or
            // spend its money.
            self::ManageBilling,
            self::ManageApiKeys,
            self::ManageGatewayCredentials,
            self::ManageStaff,
            self::DeleteTenant => [Role::Owner],

            // Everything a manager needs to run the operation day to day.
            self::ManageCatalogue,
            self::ManagePricing,
            self::ManageBookings,
            self::ViewFinancials,
            self::ViewGuestDocuments,
            self::ManageBranding,
            self::ExportData,
            // ADR-0025 §4: owner and manager, and crew explicitly not. This
            // matches the matrix for everything else that is money or
            // account-level — crew are read-only inside a departure window, and
            // an audit trail is neither.
            self::ViewAuditLog => [Role::Owner, Role::Manager],

            // Crew: read-only, within the window, and nothing beyond what they
            // need standing on the quay with a passenger list.
            self::ViewDepartures,
            self::ViewPaxList,
            self::CheckInGuests,
            self::ViewManifest => [Role::Owner, Role::Manager, Role::Crew],
        };
    }

    public function grantedTo(Role $role): bool
    {
        return in_array($role, $this->heldBy(), strict: true);
    }

    /** Is this a write, so read-only mode should refuse it? */
    public function isWrite(): bool
    {
        return match ($this) {
            self::ViewDepartures,
            self::ViewPaxList,
            self::ViewManifest,
            self::ViewFinancials,
            self::ViewGuestDocuments,
            // Reading the trail is a read, so TEN-9's read-only mode must not
            // refuse it. A lapsed subscription is exactly when an operator
            // wants to see what their team did.
            self::ViewAuditLog => false,
            default => true,
        };
    }
}
