<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Tenancy;

/*
 * Spec TEN-8, stated as a table rather than as prose.
 *
 * The matrix is asserted **exhaustively**: every capability against every role,
 * both the grants and the refusals. Testing only the grants would let a widened
 * capability through unnoticed, and a widened capability is the failure that
 * matters here — nobody files a bug saying "I can see more than I should".
 */

/** @return array<string, array{0: Capability, 1: list<Role>}> */
function matrix(): array
{
    return [
        // Owner alone: money, credentials, access, and ending the account.
        'manage_billing' => [Capability::ManageBilling, [Role::Owner]],
        'manage_api_keys' => [Capability::ManageApiKeys, [Role::Owner]],
        'manage_gateway_credentials' => [Capability::ManageGatewayCredentials, [Role::Owner]],
        'manage_staff' => [Capability::ManageStaff, [Role::Owner]],
        'delete_tenant' => [Capability::DeleteTenant, [Role::Owner]],

        // Owner and manager: running the business.
        'manage_catalogue' => [Capability::ManageCatalogue, [Role::Owner, Role::Manager]],
        'manage_pricing' => [Capability::ManagePricing, [Role::Owner, Role::Manager]],
        'manage_bookings' => [Capability::ManageBookings, [Role::Owner, Role::Manager]],
        'view_financials' => [Capability::ViewFinancials, [Role::Owner, Role::Manager]],
        'view_guest_documents' => [Capability::ViewGuestDocuments, [Role::Owner, Role::Manager]],
        'manage_branding' => [Capability::ManageBranding, [Role::Owner, Role::Manager]],
        'export_data' => [Capability::ExportData, [Role::Owner, Role::Manager]],

        // Crew too: what someone needs standing on the quay.
        'view_departures' => [Capability::ViewDepartures, [Role::Owner, Role::Manager, Role::Crew]],
        'view_pax_list' => [Capability::ViewPaxList, [Role::Owner, Role::Manager, Role::Crew]],
        'check_in_guests' => [Capability::CheckInGuests, [Role::Owner, Role::Manager, Role::Crew]],
        'view_manifest' => [Capability::ViewManifest, [Role::Owner, Role::Manager, Role::Crew]],
    ];
}

dataset('capability matrix', fn (): array => array_map(
    static fn (array $row): array => $row,
    matrix(),
));

function userWithRole(Role $role): User
{
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create();

    Tenancy::forTenant($tenant, fn () => RoleAssignment::query()->create([
        'user_id' => $user->getKey(),
        'role' => $role,
    ]));

    return $user->refresh();
}

it('grants a capability to exactly the roles TEN-8 names', function (Capability $capability, array $holders): void {
    foreach (Role::cases() as $role) {
        $user = userWithRole($role);
        $expected = in_array($role, $holders, strict: true);

        expect($user->hasCapability($capability))->toBe($expected, sprintf(
            '%s should %sbe granted [%s]',
            $role->value,
            $expected ? '' : 'NOT ',
            $capability->value,
        ));
    }
})->with('capability matrix')->group('fast');

it('covers every capability in the matrix, so a new one cannot slip through untested', function (): void {
    // Without this, adding a case to the enum and forgetting a row here would
    // leave it silently unasserted — and an unasserted capability defaults to
    // whatever someone typed in `heldBy()`.
    $tested = array_map(static fn (array $row): string => $row[0]->value, array_values(matrix()));
    $all = array_map(static fn (Capability $c): string => $c->value, Capability::cases());

    sort($tested);
    sort($all);

    expect($tested)->toBe($all);
})->group('fast');

it('gives crew no pricing, no financials and no guest documents', function (): void {
    // Stated separately from the matrix because it is the sentence in TEN-8
    // that a reader is most likely to check, and the one most costly to get
    // wrong: crew are seasonal staff with a shared phone.
    $crew = userWithRole(Role::Crew);

    expect($crew->hasCapability(Capability::ManagePricing))->toBeFalse()
        ->and($crew->hasCapability(Capability::ViewFinancials))->toBeFalse()
        ->and($crew->hasCapability(Capability::ViewGuestDocuments))->toBeFalse()
        ->and($crew->hasCapability(Capability::ManageCatalogue))->toBeFalse()
        ->and($crew->hasCapability(Capability::ExportData))->toBeFalse();
})->group('fast');

it('refuses a manager billing, API keys and gateway credentials', function (): void {
    $manager = userWithRole(Role::Manager);

    expect($manager->hasCapability(Capability::ManageBilling))->toBeFalse()
        ->and($manager->hasCapability(Capability::ManageApiKeys))->toBeFalse()
        ->and($manager->hasCapability(Capability::ManageGatewayCredentials))->toBeFalse()
        // …but everything needed to actually run the operation.
        ->and($manager->hasCapability(Capability::ManageBookings))->toBeTrue()
        ->and($manager->hasCapability(Capability::ManageCatalogue))->toBeTrue();
})->group('fast');

it('applies the union of abilities when a user holds two roles', function (): void {
    // A small operation: runs the office and skippers on Sundays. This is why
    // role_assignments allows multiple rows rather than a single role column.
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create();

    Tenancy::forTenant($tenant, function () use ($user): void {
        RoleAssignment::query()->create(['user_id' => $user->getKey(), 'role' => Role::Manager]);
        RoleAssignment::query()->create(['user_id' => $user->getKey(), 'role' => Role::Crew]);
    });

    $user->refresh();

    expect($user->hasCapability(Capability::ManageCatalogue))->toBeTrue()
        ->and($user->hasCapability(Capability::CheckInGuests))->toBeTrue()
        // The union widens, it does not escalate: neither role grants billing.
        ->and($user->hasCapability(Capability::ManageBilling))->toBeFalse();
})->group('fast');

it('gives a user with no role assignment nothing at all', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create();

    foreach (Capability::cases() as $capability) {
        expect($user->hasCapability($capability))->toBeFalse("unassigned user was granted [{$capability->value}]");
    }
})->group('fast');

it('gives a super-admin no operator capability', function (): void {
    // A super-admin is not an operator. Reaching an operator's data is
    // impersonation (TEN-7), an audited action, not an implicit privilege.
    $admin = User::factory()->superAdmin()->create();

    foreach (Capability::cases() as $capability) {
        expect($admin->hasCapability($capability))->toBeFalse("super-admin was granted [{$capability->value}]");
    }
})->group('fast');
