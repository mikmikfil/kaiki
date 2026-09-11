<?php

declare(strict_types=1);

use App\Domain\Tenancy\Support\PlanLimits;
use App\Enums\DomainStatus;
use App\Enums\Plan;
use App\Enums\Role;
use App\Enums\VesselStatus;
use App\Enums\VesselType;
use App\Filament\App\Pages\Domains;
use App\Filament\App\Resources\VesselResource\Pages\CreateVessel;
use App\Filament\App\Resources\VesselResource\Pages\ListVessels;
use App\Filament\App\Resources\WebhookEndpointResource\Pages\ListWebhookEndpoints;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;
use App\Models\Vessel;
use App\Support\Tenancy;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| SAA-8 — plan limits, at the point of creation
|--------------------------------------------------------------------------
|
| > Plan limits are enforced at the point of creation with a clear upgrade
| > path: creating a sixth vessel on `Fleet` is blocked with a localised
| > message and an upgrade link, never silently truncated.
|
| `Plan` had the three rules since M0 and nothing read them. Two halves are
| asserted here: the refusal, and "never silently truncated" — an operator
| already over a limit keeps everything they have.
|
*/

function planOwner(Plan $plan, int $vessels = 0): User
{
    $tenant = Tenant::factory()->create(['plan' => $plan]);

    Tenancy::forTenant($tenant, static function () use ($vessels): void {
        for ($i = 1; $i <= $vessels; $i++) {
            Vessel::factory()->named("Σκάφος {$i}")->create();
        }
    });

    return OperatorUser::withRole(Role::Owner, $tenant);
}

function planTenantOf(User $user): Tenant
{
    return Tenant::query()->findOrFail($user->tenant_id);
}

function planPage(User $user, string $page): Testable
{
    tenancy()->initialize(planTenantOf($user));

    return Livewire::actingAs($user)->test($page);
}

function planVesselCount(User $user): int
{
    return Tenancy::forTenant(planTenantOf($user), static fn (): int => Vessel::query()->count());
}

/** @return array<string, mixed> */
function planVesselData(string $name): array
{
    return [
        'name' => $name,
        'type' => VesselType::TraditionalKaiki->value,
        'status' => VesselStatus::Active->value,
        'capacity_max' => 12,
        'crew_count' => 2,
        'description' => ['el' => 'Καΐκι', 'en' => 'A kaiki'],
    ];
}

it('lets a Solo operator have their one boat, and refuses a second with the reason', function (): void {
    $owner = planOwner(Plan::Solo, vessels: 1);

    planPage($owner, CreateVessel::class)
        ->fillForm(planVesselData('Δεύτερο'))
        ->call('create')
        ->assertNotified(__('plans.vessels.reached_title'));

    expect(planVesselCount($owner))->toBe(1);
})->group('fast');

it('lets a Fleet operator add a fifth boat and not a sixth', function (): void {
    $owner = planOwner(Plan::Fleet, vessels: 4);

    planPage($owner, CreateVessel::class)
        ->fillForm(planVesselData('Πέμπτο'))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(planVesselCount($owner))->toBe(5);

    planPage($owner, CreateVessel::class)
        ->fillForm(planVesselData('Έκτο'))
        ->call('create')
        ->assertNotified(__('plans.vessels.reached_title'));

    // SAA-8's own example, exactly.
    expect(planVesselCount($owner))->toBe(5);
})->group('fast');

it('puts no vessel limit on Pro', function (): void {
    $owner = planOwner(Plan::Pro, vessels: 7);

    planPage($owner, CreateVessel::class)
        ->fillForm(planVesselData('Όγδοο'))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(planVesselCount($owner))->toBe(8);
})->group('fast');

it('never takes away the boats of an operator already over the limit', function (): void {
    // "Never silently truncated". A Solo operator with three boats — moved down
    // a plan, or created before limits were enforced — keeps and sees all
    // three; only the "new" button changes, into the way to a bigger plan.
    $owner = planOwner(Plan::Solo, vessels: 3);

    $vessels = Tenancy::forTenant(planTenantOf($owner), static fn () => Vessel::query()->get());

    planPage($owner, ListVessels::class)
        ->assertCanSeeTableRecords($vessels)
        ->assertActionHidden('create')
        ->assertActionVisible('upgrade');

    expect(planVesselCount($owner))->toBe(3);
})->group('fast');

it('tells an operator how many of their plan\'s boats they use', function (): void {
    $owner = planOwner(Plan::Fleet, vessels: 2);

    app()->setLocale('el');

    $usage = Tenancy::forTenant(planTenantOf($owner), static fn (): ?string => PlanLimits::vesselUsage(planTenantOf($owner)));

    expect($usage)->toContain('2')->toContain('5');

    actingAs($owner)->get('/app/vessels?lang=el')->assertSuccessful()->assertSee((string) $usage);
})->group('fast');

it('offers a custom domain on Pro only, and keeps a domain an operator already has', function (): void {
    $solo = planOwner(Plan::Solo);

    Tenancy::forTenant(planTenantOf($solo), static fn () => TenantDomain::query()->create([
        'hostname' => 'book.already-mine.gr',
        'status' => DomainStatus::Verified,
        'verification_token' => 'token',
        'verified_at' => now(),
    ]));

    planPage($solo, Domains::class)
        ->fillForm(['hostname' => 'book.another.gr'])
        ->call('add')
        ->assertNotified(__('plans.pro_only'));

    // Nothing added — and the domain they already had is still there, verified.
    $domains = Tenancy::forTenant(planTenantOf($solo), static fn () => TenantDomain::query()->pluck('status', 'hostname')->all());

    expect($domains)->toBe(['book.already-mine.gr' => DomainStatus::Verified]);

    app()->setLocale('el');
    actingAs($solo)->get('/app/domains?lang=el')
        ->assertSuccessful()
        ->assertSee(__('plans.pro_only', [], 'el'))
        ->assertSee('book.already-mine.gr');

    $pro = planOwner(Plan::Pro);

    planPage($pro, Domains::class)
        ->fillForm(['hostname' => 'book.pro-operator.gr'])
        ->call('add');

    expect(Tenancy::forTenant(planTenantOf($pro), static fn (): int => TenantDomain::query()->count()))->toBe(1);
})->group('fast');

it('offers new webhooks on Pro only', function (): void {
    planPage(planOwner(Plan::Fleet), ListWebhookEndpoints::class)
        ->assertActionHidden('create')
        ->assertActionVisible('upgrade');

    planPage(planOwner(Plan::Pro), ListWebhookEndpoints::class)
        ->assertActionVisible('create')
        ->assertActionHidden('upgrade');
})->group('fast');

it('sends "upgrade" somewhere real even before billing exists', function (): void {
    config(['kaiki.plans.upgrade_url' => null, 'mail.from.address' => 'hello@kaiki.example']);

    expect(PlanLimits::upgradeUrl())->toBe('mailto:hello@kaiki.example');

    config(['kaiki.plans.upgrade_url' => 'https://kaiki.example/plans']);

    expect(PlanLimits::upgradeUrl())->toBe('https://kaiki.example/plans');
})->group('fast');
