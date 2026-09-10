<?php

declare(strict_types=1);

use App\Domain\Tenancy\Actions\CheckDomains;
use App\Domain\Tenancy\Support\DnsLookup;
use App\Enums\DomainStatus;
use App\Enums\HostedSiteMode;
use App\Jobs\CheckCustomDomains;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Support\Tenancy;
use Illuminate\Console\Scheduling\Schedule;

use function Pest\Laravel\get;

use Tests\Support\Tenancy\FakeDns;

/*
|--------------------------------------------------------------------------
| #109: the sweep, and the outage it refuses to cause
|--------------------------------------------------------------------------
|
| Verification cannot be synchronous only. An operator adds a CNAME at their
| registrar and it propagates in anything from a minute to a day; a flow that
| only checked when they pressed a button would leave them pressing it — or
| concluding the feature is broken and telephoning about it.
|
| The half of this file that matters more is the other one: a domain that stops
| resolving is **recorded and still served**. From the platform's side a resolver
| hiccup, a registrar's maintenance window and "the operator deleted the record"
| are the same silence, and only one of the three is worth taking a working site
| down for.
|
*/

/** See `CustomDomainTest` for why this is a function and not `$this->dns`. */
function sweepDns(): FakeDns
{
    $bound = app(DnsLookup::class);

    if ($bound instanceof FakeDns) {
        return $bound;
    }

    $fake = new FakeDns;

    app()->instance(DnsLookup::class, $fake);

    return $fake;
}

it('finishes the verification an operator started and went to bed on', function (): void {
    $tenant = Tenant::factory()->create(['hosted_site_mode' => HostedSiteMode::Full]);

    $domain = Tenancy::forTenant($tenant, static fn () => TenantDomain::query()->create([
        'hostname' => 'overnight.example.gr',
        'status' => DomainStatus::Pending,
        'verification_token' => 'token',
    ]));

    // The registrar got round to it while nobody was watching.
    sweepDns()->points('overnight.example.gr', (string) config('kaiki.tenancy.hosted_host'));

    (new CheckCustomDomains)->handle(app(CheckDomains::class));

    expect($domain->refresh()->status)->toBe(DomainStatus::Verified);

    // And the page is live on it, with no further action from anybody.
    get('http://overnight.example.gr/')->assertOk();
})->group('fast');

it('records a verified domain that stopped resolving and keeps serving it', function (): void {
    $tenant = Tenant::factory()->create(['hosted_site_mode' => HostedSiteMode::Full]);

    $domain = Tenancy::forTenant($tenant, static fn () => TenantDomain::query()->create([
        'hostname' => 'glitch.example.gr',
        'status' => DomainStatus::Verified,
        'verification_token' => 'token',
        'verified_at' => now()->subDays(30),
    ]));

    // Silence from the resolver.
    sweepDns()->points('glitch.example.gr');

    (new CheckCustomDomains)->handle(app(CheckDomains::class));

    $domain->refresh();

    expect($domain->status)->toBe(DomainStatus::Verified)
        ->and($domain->last_checked_at)->not->toBeNull();

    // The operator's guests never noticed, which is the whole requirement.
    get('http://glitch.example.gr/')->assertOk();
})->group('fast');

it('is scheduled rather than left to a button', function (): void {
    $schedule = app(Schedule::class);

    $names = collect($schedule->events())
        ->map(fn ($event): string => (string) $event->description)
        ->implode(' ');

    // The sweep is what makes the feature work for somebody who is not sitting
    // on the page. A test for it here rather than in `CiGatesTest` because it
    // is a property of this feature, not of the pipeline.
    expect($names)->toContain('domains:check');
})->group('fast');
