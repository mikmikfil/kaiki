<?php

declare(strict_types=1);

use App\Domain\Tenancy\Actions\CheckDomains;
use App\Domain\Tenancy\Actions\VerifyDomain;
use App\Domain\Tenancy\Support\DnsLookup;
use App\Enums\DomainStatus;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Support\Tenancy;
use Illuminate\Database\QueryException;

use function Pest\Laravel\get;

use Tests\Support\Tenancy\FakeDns;

/*
|--------------------------------------------------------------------------
| #109: an operator's own domain (HOS-3, ADR-0010 Option A)
|--------------------------------------------------------------------------
|
| Three claims, and the second is the one that keeps an operator online.
|
| **Only a verified hostname is served.** A pending row is not a claim of
| ownership; honouring one would let anybody point DNS at the platform and be
| handed another operator's catalogue.
|
| **A verified domain that stops resolving keeps serving.** DNS is not reliable
| enough to be a kill switch: a resolver hiccup, a registrar's maintenance
| window and "the operator deleted the record" are indistinguishable from here,
| and only one of them is worth taking a site down for.
|
| **A hostname belongs to one tenant.** SEC-4, and the failure is not subtle — a
| second tenant verifying somebody else's hostname would start serving their
| guests.
|
*/

/**
 * The internet, answering for this test.
 *
 * A function rather than `$this->dns` in a `beforeEach`: inside a Pest closure
 * `$this` is a `TestCall` at analysis time, which is the same thing that bit
 * `$this->fail()` in #89. The container is fresh per test, so the first call
 * binds the fake and every later one finds it.
 */
function fakeDns(): FakeDns
{
    $bound = app(DnsLookup::class);

    if ($bound instanceof FakeDns) {
        return $bound;
    }

    $fake = new FakeDns;

    app()->instance(DnsLookup::class, $fake);

    return $fake;
}

function domainFor(Tenant $tenant, string $hostname, DomainStatus $status = DomainStatus::Pending): TenantDomain
{
    return Tenancy::forTenant($tenant, static fn (): TenantDomain => TenantDomain::query()->create([
        'hostname' => $hostname,
        'status' => $status,
        'verification_token' => bin2hex(random_bytes(8)),
        'verified_at' => $status === DomainStatus::Verified ? now() : null,
    ]));
}

it('verifies a hostname whose CNAME points at the platform', function (): void {
    $tenant = Tenant::factory()->create(['hosted_page_enabled' => true]);
    $domain = domainFor($tenant, 'book.example.gr');

    fakeDns()->points('book.example.gr', (string) config('kaiki.tenancy.hosted_host'));

    expect(app(VerifyDomain::class)($domain))->toBeTrue()
        ->and($domain->refresh()->status)->toBe(DomainStatus::Verified)
        ->and($domain->verified_at)->not->toBeNull()
        ->and($domain->last_checked_at)->not->toBeNull();
})->group('fast');

it('fails a pending hostname that points somewhere else, and says nothing was verified', function (): void {
    $tenant = Tenant::factory()->create(['hosted_page_enabled' => true]);
    $domain = domainFor($tenant, 'book.example.gr');

    // Pointed at a competitor's load balancer, or simply not created yet.
    fakeDns()->points('book.example.gr', 'somewhere.else.example');

    expect(app(VerifyDomain::class)($domain))->toBeFalse()
        ->and($domain->refresh()->status)->toBe(DomainStatus::Failed)
        ->and($domain->verified_at)->toBeNull();
})->group('fast');

it('keeps serving a verified domain that stops resolving', function (): void {
    $tenant = Tenant::factory()->create(['hosted_page_enabled' => true]);
    $domain = domainFor($tenant, 'book.example.gr', DomainStatus::Verified);

    // The registrar is having a bad afternoon. Nothing about the operator's
    // configuration changed.
    fakeDns()->points('book.example.gr');

    expect(app(VerifyDomain::class)($domain))->toBeFalse()
        // Recorded and reported — and still verified, because a glitch must not
        // take an operator's site down.
        ->and($domain->refresh()->status)->toBe(DomainStatus::Verified)
        ->and($domain->last_checked_at)->not->toBeNull();
})->group('fast');

it('serves the operator page on a verified hostname and nothing on a pending one', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'aegean', 'hosted_page_enabled' => true]);

    domainFor($tenant, 'pending.example.gr');
    domainFor($tenant, 'live.example.gr', DomainStatus::Verified);

    // Escaped, for the reason #104 recorded: a faker company name with an
    // apostrophe in it is `&#039;` in the markup.
    get('http://live.example.gr/')->assertOk()->assertSee(e($tenant->name), escape: false);

    // TEN-4 step 2: an unverified hostname resolves to **nothing**, not to a
    // default tenant. The difference is somebody else's catalogue.
    get('http://pending.example.gr/')->assertNotFound();
})->group('fast');

it('refuses a hostname another tenant has already claimed', function (): void {
    $first = Tenant::factory()->create(['hosted_page_enabled' => true]);
    $second = Tenant::factory()->create(['hosted_page_enabled' => true]);

    domainFor($first, 'book.example.gr', DomainStatus::Verified);

    // SEC-4. The unique index is the backstop the panel's own check sits in
    // front of, and the claim that matters either way.
    expect(fn () => domainFor($second, 'book.example.gr'))->toThrow(QueryException::class);
})->group('fast');

it('sweeps every domain that is waiting, and leaves a disabled one alone', function (): void {
    $tenant = Tenant::factory()->create(['hosted_page_enabled' => true]);

    $pending = domainFor($tenant, 'pending.example.gr');
    $failed = domainFor($tenant, 'failed.example.gr', DomainStatus::Failed);
    $disabled = domainFor($tenant, 'off.example.gr', DomainStatus::Disabled);

    fakeDns()->points('pending.example.gr', (string) config('kaiki.tenancy.hosted_host'));
    fakeDns()->points('failed.example.gr', (string) config('kaiki.tenancy.hosted_host'));
    fakeDns()->points('off.example.gr', (string) config('kaiki.tenancy.hosted_host'));

    $result = app(CheckDomains::class)();

    expect($result['verified'])->toBe(2)
        ->and($pending->refresh()->status)->toBe(DomainStatus::Verified)
        // A failed row is a domain still waiting for DNS, so the sweep picks it
        // up the moment the record lands.
        ->and($failed->refresh()->status)->toBe(DomainStatus::Verified)
        // `disabled` is the operator's own decision, and a cron job does not
        // re-litigate it.
        ->and($disabled->refresh()->status)->toBe(DomainStatus::Disabled);
})->group('fast');

it('verifies an A record pointed at the platform as well as a CNAME', function (): void {
    $tenant = Tenant::factory()->create(['hosted_page_enabled' => true]);
    $domain = domainFor($tenant, 'apex.example.gr');

    // A registrar that refuses a CNAME on an apex leaves an operator with an A
    // record, and refusing to verify that would be refusing a domain that works.
    fakeDns()->points('apex.example.gr', (string) config('kaiki.tenancy.custom_domain_target'));

    expect(app(VerifyDomain::class)($domain))->toBeTrue();
})->group('fast');
