<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Filament\Admin\Resources\TenantResource;
use App\Filament\Admin\Widgets\PlatformOverview;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

use Tests\Support\OperatorUser;

/*
 * The platform merchant list — the read-only half of SAA-1, pulled forward from
 * M7 because `/admin` has been an empty panel since #9 and the platform owner
 * has had no way to see their own operators.
 *
 * Two things make this resource different from every other one in the codebase,
 * and both are asserted here rather than assumed:
 *
 *   1. **It is deliberately cross-tenant.** `tenants` is not tenant-owned — it
 *      *is* the tenant — so `BelongsToTenant` does not apply and the list must
 *      show every operator. Everywhere else in this application a query
 *      returning two tenants' rows is the bug (#8); here it is the feature, and
 *      the test below is what distinguishes the two.
 *
 *   2. **It is read-only, and that is load-bearing.** The moment `/admin` can
 *      change an operator's record, SEC-16 applies and #42's undecided ADR-0025
 *      becomes a blocker. Read-only is what lets this ship before that decision.
 */

/**
 * The list URL on the `admin` panel.
 *
 * `AppPanelProvider` is `->default()`, so `getUrl()` with no panel resolves
 * against `/app` and throws `RouteNotFoundException`. Naming the panel is not
 * optional here, and getting it wrong is a confusing failure rather than an
 * obvious one.
 */
function adminUrl(): string
{
    return TenantResource::getUrl('index', panel: 'admin');
}

function superAdmin(): User
{
    return User::factory()->superAdmin()->create();
}

/**
 * Two operators with deliberately different plans, statuses and languages.
 *
 * @return array{0: Tenant, 1: Tenant}
 */
function twoMerchants(): array
{
    return [
        Tenant::factory()->create([
            'name' => 'Aegean Blue Cruises',
            'slug' => 'aegean-blue',
            'plan' => Plan::Fleet,
            'status' => TenantStatus::Active,
            'default_locale' => 'el',
        ]),
        Tenant::factory()->create([
            'name' => 'Ionian Sunset',
            'slug' => 'ionian-sunset',
            'plan' => Plan::Solo,
            'status' => TenantStatus::Trialing,
            'default_locale' => 'en',
        ]),
    ];
}

it('lists every merchant, across tenants', function (): void {
    // The assertion that would fail the day someone "helpfully" adds
    // BelongsToTenant to the Tenant model: the list would quietly show one row
    // and look perfectly fine.
    [$aegean, $ionian] = twoMerchants();

    actingAs(superAdmin())
        ->get(adminUrl())
        ->assertSuccessful()
        ->assertSee($aegean->name)
        ->assertSee($ionian->name);
})->group('fast');

it('refuses an operator, so the resource is not a second way into /admin', function (): void {
    // `canAccessPanel` already refuses at the panel boundary (#9). This proves
    // the resource does not open a door beside it — a partial render is how
    // someone learns what exists behind a wall.
    $owner = OperatorUser::withRole(Role::Owner);

    actingAs($owner)
        ->get(adminUrl())
        ->assertForbidden();
})->group('fast');

it('refuses an unauthenticated visitor', function (): void {
    get(adminUrl())->assertRedirect();
})->group('fast');

it('hides a soft-deleted merchant by default', function (): void {
    // A deleted operator's data still exists, and the platform owner is the one
    // person who may need to see that — but not by default, or the list stops
    // meaning "our merchants".
    [$aegean, $ionian] = twoMerchants();
    $ionian->delete();

    actingAs(superAdmin())
        ->get(adminUrl())
        ->assertSuccessful()
        ->assertSee($aegean->name)
        ->assertDontSee($ionian->name);
})->group('fast');

it('renders plan and status through the shared enum labels', function (): void {
    // Not a second copy of these strings. #12 consolidated every enum label
    // into `enums.php` precisely so the super-admin panel and the operator
    // panel cannot disagree about what "past due" is called.
    Tenant::factory()->create(['status' => TenantStatus::PastDue, 'plan' => Plan::Pro]);

    actingAs(superAdmin())
        ->get(adminUrl() . '?lang=en')
        ->assertSuccessful()
        ->assertSee(TenantStatus::PastDue->label())
        ->assertSee(Plan::Pro->label());
})->group('fast');

it('renders the list in Greek when Greek is asked for', function (): void {
    Tenant::factory()->create(['status' => TenantStatus::PastDue]);

    app()->setLocale('el');
    $greek = TenantStatus::PastDue->label();

    actingAs(superAdmin())
        ->get(adminUrl() . '?lang=el')
        ->assertSuccessful()
        ->assertSee($greek);
})->group('fast', 'i18n');

it('offers no way to create, edit or delete a merchant', function (): void {
    // The read-only constraint, asserted rather than trusted to the absence of
    // a page. Filament allows an action when no policy forbids it, so "we did
    // not build an edit form" is not the same as "editing is refused" — the
    // next person to add a page would get one for free.
    $tenant = Tenant::factory()->create();
    $admin = superAdmin();

    expect($admin->can('create', Tenant::class))->toBeFalse()
        ->and($admin->can('update', $tenant))->toBeFalse()
        ->and($admin->can('delete', $tenant))->toBeFalse()
        ->and($admin->can('forceDelete', $tenant))->toBeFalse()
        ->and($admin->can('restore', $tenant))->toBeFalse();

    expect(array_keys(TenantResource::getPages()))->toBe(['index']);
})->group('fast');

it('lets a super-admin view but never write', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = superAdmin();

    expect($admin->can('viewAny', Tenant::class))->toBeTrue()
        ->and($admin->can('view', $tenant))->toBeTrue();
})->group('fast');

it('refuses an operator at the policy, not only at the panel', function (): void {
    // Defence in depth. If the panel boundary is ever relaxed — impersonation
    // in M7 is exactly the change that would do it — the policy still holds.
    $owner = OperatorUser::withRole(Role::Owner);
    $other = Tenant::factory()->create();

    expect($owner->can('viewAny', Tenant::class))->toBeFalse()
        ->and($owner->can('view', $other))->toBeFalse()
        ->and($owner->can('view', $owner->tenant))->toBeFalse();
})->group('fast');

it('shows the platform overview on the /admin landing page', function (): void {
    twoMerchants();

    actingAs(superAdmin())
        ->get('/admin?lang=en')
        ->assertSuccessful()
        ->assertSee(__('tenants.overview.total'))
        ->assertSee(__('tenants.overview.past_due'));
})->group('fast');

it('registers the overview widget on the admin panel, and only there', function (): void {
    // Discovered rather than listed, so this is what catches the directory
    // being renamed or the widget landing under the wrong panel — where it
    // would show every operator's counts to an operator.
    $widgetsOn = static function (string $panel): array {
        $names = [];

        foreach (Filament::getPanel($panel)->getWidgets() as $widget) {
            $names[] = is_string($widget) ? $widget : $widget::class;
        }

        return $names;
    };

    expect($widgetsOn('admin'))->toContain(PlatformOverview::class);
    expect($widgetsOn('app'))->not->toContain(PlatformOverview::class);
})->group('fast');

it('counts merchants by status, excluding deleted accounts', function (): void {
    // A cancelled account is not a merchant. Counting it would make the total
    // disagree with the list directly beneath it, which is worse than either
    // number on its own.
    Tenant::factory()->count(3)->create(['status' => TenantStatus::Active]);
    Tenant::factory()->count(2)->create(['status' => TenantStatus::Trialing]);
    Tenant::factory()->create(['status' => TenantStatus::PastDue]);
    Tenant::factory()->create(['status' => TenantStatus::Active])->delete();

    $counts = PlatformOverview::countsByStatus();

    expect($counts[TenantStatus::Active->value])->toBe(3)
        ->and($counts[TenantStatus::Trialing->value])->toBe(2)
        ->and($counts[TenantStatus::PastDue->value])->toBe(1)
        ->and(array_sum($counts))->toBe(6);
})->group('fast');

it('reports nothing rather than breaking on an empty platform', function (): void {
    // The first thing a new deployment renders. A missing key here would be a
    // 500 on the very first page the platform owner ever opens.
    expect(PlatformOverview::countsByStatus())->toBe([]);

    actingAs(superAdmin())->get('/admin')->assertSuccessful();
})->group('fast');
