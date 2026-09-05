<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Filament\App\Resources\AuditLogResource;
use App\Filament\App\Resources\AuditLogResource\Pages\ListAuditLogs;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| The audit trail in /app — ADR-0025 §4, TEN-8, SEC-16
|--------------------------------------------------------------------------
|
| ADR-0025's argument for a screen at all: a leaked key is *"precisely when"* an
| operator needs to see their own team's actions, and the `Log::info` it replaced
| could only be read by someone with a shell on the Hetzner box.
|
| Two properties are asserted here that nothing else asserts at the screen:
| **crew never see it**, and **one operator never sees another's**. The second is
| also covered automatically by #8's isolation gate, which now discovers
| `AuditLog` like every other tenant-owned model — this states it where an
| operator would actually notice.
|
*/

function auditTenantOf(User $user): Tenant
{
    return Tenant::query()->findOrFail($user->tenant_id);
}

/**
 * The mounted list page, with the tenant resolved by hand.
 *
 * A Livewire component test does not pass through the panel's middleware, so
 * `ResolveTenant` never runs and every query on a tenant-owned model throws.
 * The HTTP test below proves the middleware does this in production.
 */
function auditPanelAs(User $user): Testable
{
    tenancy()->initialize(auditTenantOf($user));

    return Livewire::actingAs($user)->test(ListAuditLogs::class);
}

it('lets an owner and a manager read the trail', function (Role $role): void {
    $user = OperatorUser::withRole($role);

    Tenancy::forTenant(auditTenantOf($user), fn () => AuditLog::factory()->create());

    auditPanelAs($user)->assertSuccessful();
})->with([
    'owner' => Role::Owner,
    'manager' => Role::Manager,
])->group('fast');

it('refuses a crew member', function (): void {
    $crew = OperatorUser::withRole(Role::Crew);

    // ADR-0025 §4 gates this on its own `ViewAuditLog` capability rather than
    // reusing another — a trail readable as a side effect of some other
    // permission is a trail whose audience changes the next time that
    // permission is widened.
    actingAs($crew)->get('/app/audit-logs')->assertForbidden();
})->group('fast');

it('shows one operator nothing of another', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    $other = Tenant::factory()->create();

    Tenancy::forTenant($other, function () use ($other): void {
        User::factory()->for($other)->create();
        AuditLog::factory()->create(['subject_label' => 'pk_live_someoneelse']);
    });

    $mine = Tenancy::forTenant(
        auditTenantOf($owner),
        fn (): AuditLog => AuditLog::factory()->create(['subject_label' => 'pk_live_mine']),
    );

    auditPanelAs($owner)
        ->assertCanSeeTableRecords([$mine])
        ->assertCountTableRecords(1);
})->group('fast');

it('offers nothing that would change a row', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant(auditTenantOf($owner), fn () => AuditLog::factory()->create());

    // Three layers say the same thing — the policy refuses every write ability,
    // the model throws, and the resource registers no create or edit page. This
    // asserts the third, which is the one an operator can see: a delete button
    // that always errors is worse than no button at all.
    expect(array_keys(AuditLogResource::getPages()))->toBe(['index']);

    // Split rather than chained: `assertSuccessful()` is typed as returning a
    // `TestResponse` in Livewire's stubs, and chaining a table assertion off it
    // is a PHPStan error rather than a runtime one.
    $page = auditPanelAs($owner);
    $page->assertSuccessful();
    $page->assertCountTableRecords(1);
})->group('fast');

it('lists newest first', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    [$older, $newer] = Tenancy::forTenant(auditTenantOf($owner), function (): array {
        $older = AuditLog::factory()->create(['created_at' => now()->subDay()]);
        $newer = AuditLog::factory()
            ->action(AuditAction::VesselDeleted)
            ->create(['created_at' => now()]);

        return [$older, $newer];
    });

    // Newest first is the ordering an incident is read in: the thing that just
    // happened is the thing being investigated.
    auditPanelAs($owner)->assertCanSeeTableRecords([$newer, $older], inOrder: true);
})->group('fast');
