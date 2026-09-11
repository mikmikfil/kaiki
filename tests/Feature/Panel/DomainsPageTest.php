<?php

declare(strict_types=1);

use App\Enums\DomainStatus;
use App\Enums\Plan;
use App\Enums\Role;
use App\Filament\App\Pages\Domains;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;
use App\Support\Tenancy;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
 * #109, TEN-8 and SEC-4: who may point a domain at the platform.
 *
 * A custom domain is what the business calls itself in public — the same kind of
 * decision as the logo — so it is gated the way branding is, and crew reach
 * neither.
 *
 * The claim worth a test of its own is the cross-tenant one: a second operator
 * must not be able to claim a hostname the first has verified, because the
 * second one to verify would start serving the first one's guests.
 */

it('lets an owner and a manager reach the domains screen', function (Role $role): void {
    actingAs(OperatorUser::withRole($role))->get('/app/domains')->assertSuccessful();
})->with([[Role::Owner], [Role::Manager]])->group('fast');

it('refuses crew the domains screen', function (): void {
    actingAs(OperatorUser::withRole(Role::Crew))->get('/app/domains')->assertForbidden();
})->group('fast');

/**
 * An owner on Pro — the only plan with a custom domain (SAA-3). The tests below
 * are about what the screen does with a hostname, and on a smaller plan the
 * screen refuses before looking at one (`PlanLimitsTest` covers that), which
 * would let a "nothing was created" assertion pass for the wrong reason.
 */
function domainsProOwner(): User
{
    return OperatorUser::withRole(Role::Owner, Tenant::factory()->create(['plan' => Plan::Pro]));
}

it('adds a hostname as pending, never as verified', function (): void {
    $owner = domainsProOwner();

    tenancy()->initialize(Tenant::query()->findOrFail($owner->tenant_id));

    Livewire::actingAs($owner)
        ->test(Domains::class)
        ->fillForm(['hostname' => 'Book.Example.GR'])
        ->call('add');

    $domain = TenantDomain::query()->firstOrFail();

    // Normalised on the way in, and pending until the DNS says otherwise —
    // nothing is served and no certificate is requested for it.
    expect($domain->hostname)->toBe('book.example.gr')
        ->and($domain->status)->toBe(DomainStatus::Pending);
})->group('fast');

it('refuses a hostname another operator already registered', function (): void {
    $owner = domainsProOwner();
    $other = Tenant::factory()->create();

    Tenancy::forTenant($other, static fn () => TenantDomain::query()->create([
        'hostname' => 'taken.example.gr',
        'status' => DomainStatus::Verified,
        'verification_token' => 'token',
        'verified_at' => now(),
    ]));

    tenancy()->initialize(Tenant::query()->findOrFail($owner->tenant_id));

    Livewire::actingAs($owner)
        ->test(Domains::class)
        ->fillForm(['hostname' => 'taken.example.gr'])
        ->call('add');

    // Nothing created for this tenant. The check reads **without** tenancy on
    // purpose: scoped, it would find nothing and let two operators claim one
    // hostname.
    expect(TenantDomain::query()->count())->toBe(0);
})->group('fast');

it('refuses something that is not a hostname, and the platform own address', function (): void {
    $owner = domainsProOwner();

    tenancy()->initialize(Tenant::query()->findOrFail($owner->tenant_id));

    foreach (['not a domain', 'http://book.example.gr', (string) config('kaiki.tenancy.hosted_host')] as $bad) {
        Livewire::actingAs($owner)
            ->test(Domains::class)
            ->fillForm(['hostname' => $bad])
            ->call('add');
    }

    expect(TenantDomain::query()->count())->toBe(0);
})->group('fast');
