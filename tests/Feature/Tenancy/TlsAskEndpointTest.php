<?php

declare(strict_types=1);

use App\Enums\DomainStatus;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Support\Tenancy;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| #109: the ask endpoint, which is the dangerous one
|--------------------------------------------------------------------------
|
| On-demand TLS means the server obtains a certificate for whatever hostname
| arrives, **provided this endpoint approves it**. An endpoint that answered
| broadly would let a stranger point any DNS record at the platform and make it
| request certificates until the certificate authority's rate limit is exhausted
| — for every operator at once, executed with a DNS record and a browser.
|
| So the refusals are asserted first and in every shape. An approval test passing
| says nothing at all about the property that matters.
|
*/

/** @return TestResponse<Response> */
function askFor(string $hostname): TestResponse
{
    return get('/tls/ask?domain=' . urlencode($hostname));
}

it('refuses a hostname nobody has registered', function (): void {
    askFor('attacker.example')->assertForbidden();
})->group('fast');

it('refuses a hostname that is registered but not verified', function (): void {
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, static fn () => TenantDomain::query()->create([
        'hostname' => 'pending.example.gr',
        'status' => DomainStatus::Pending,
        'verification_token' => 'token',
    ]));

    // A pending row is somebody's intention, not a proof of ownership. Issuing
    // a certificate for it would be issuing on an intention.
    askFor('pending.example.gr')->assertForbidden();
})->group('fast');

it('refuses a hostname whose verification failed, and one that was disabled', function (): void {
    $tenant = Tenant::factory()->create();

    foreach ([DomainStatus::Failed, DomainStatus::Disabled] as $index => $status) {
        $hostname = "host{$index}.example.gr";

        Tenancy::forTenant($tenant, static fn () => TenantDomain::query()->create([
            'hostname' => $hostname,
            'status' => $status,
            'verification_token' => 'token',
        ]));

        askFor($hostname)->assertForbidden();
    }
})->group('fast');

it('refuses an empty and a malformed request', function (): void {
    get('/tls/ask')->assertForbidden();
    askFor('')->assertForbidden();
    askFor('   ')->assertForbidden();
})->group('fast');

it('says nothing in the body, so it cannot be used to enumerate operators', function (): void {
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, static fn () => TenantDomain::query()->create([
        'hostname' => 'known.example.gr',
        'status' => DomainStatus::Pending,
        'verification_token' => 'token',
    ]));

    // A 404 saying "no such domain" and a 403 saying "not verified" would be a
    // probe oracle: ask about a hostname, learn whether the platform knows it.
    // Both refusals are the same empty 403.
    expect(askFor('known.example.gr')->getContent())->toBe('')
        ->and(askFor('unknown.example.gr')->getContent())->toBe('')
        ->and(askFor('known.example.gr')->status())->toBe(askFor('unknown.example.gr')->status());
})->group('fast');

it('approves a verified hostname, which is the whole point of the feature', function (): void {
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, static fn () => TenantDomain::query()->create([
        'hostname' => 'live.example.gr',
        'status' => DomainStatus::Verified,
        'verification_token' => 'token',
        'verified_at' => now(),
    ]));

    askFor('live.example.gr')->assertOk();

    // Case and a trailing dot are how a resolver may present a hostname, and
    // refusing either would mean a certificate never issued for a domain that
    // is verified.
    askFor('LIVE.example.gr')->assertOk();
    askFor('live.example.gr.')->assertOk();
})->group('fast');
