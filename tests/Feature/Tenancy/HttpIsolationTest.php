<?php

declare(strict_types=1);

use App\Domain\Tenancy\Actions\GenerateApiKey;
use App\Enums\ApiKeyType;
use App\Enums\ApiScope;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\withHeader;

/*
 * Isolation at the HTTP boundary (SEC-2).
 *
 * The distinction this file exists for: **404, never 403.**
 *
 * A 403 says "this exists, but not for you", which is a disclosure in itself.
 * Anyone holding a publishable key — and a publishable key is designed to be
 * readable in page source — could then probe uuids and learn which ones are
 * real, how many an operator has, and roughly when they were created. A 404
 * says only "no".
 */

beforeEach(function (): void {
    // Debug off. With it on, Laravel attaches a stack trace to the 404 body and
    // the two responses below differ by a line number — which would make the
    // indistinguishability assertion measure the debug renderer rather than the
    // behaviour production actually ships.
    config()->set('app.debug', false);

    Route::middleware('api.key')->get('/_iso/domains/{id}', function (string $id) {
        // Deliberately the naive shape a controller would take: look the record
        // up by id and return it. The global scope is the only thing standing
        // between this and a cross-tenant read, which is exactly what makes it
        // the right thing to test.
        $domain = TenantDomain::query()->find($id);

        abort_if($domain === null, 404);

        return response()->json(['hostname' => $domain->hostname]);
    });
});

function keyFor(Tenant $tenant): string
{
    return Tenancy::forTenant($tenant, fn (): string => (new GenerateApiKey)(
        name: 'Isolation test',
        type: ApiKeyType::Publishable,
        scopes: ApiScope::readScopes(),
    )->plainTextKey);
}

it('serves a tenant its own record', function (): void {
    $a = Tenant::factory()->create();
    $own = Tenancy::forTenant($a, fn (): TenantDomain => TenantDomain::factory()->create(['hostname' => 'mine.example']));

    withHeader('Authorization', 'Bearer ' . keyFor($a))
        ->getJson("/_iso/domains/{$own->getKey()}")
        ->assertOk()
        ->assertJson(['hostname' => 'mine.example']);
})->group('tenancy', 'fast');

it('returns 404 and not 403 for another tenant record', function (): void {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    $ofB = Tenancy::forTenant($b, fn (): TenantDomain => TenantDomain::factory()->create(['hostname' => 'theirs.example']));

    $response = withHeader('Authorization', 'Bearer ' . keyFor($a))
        ->getJson("/_iso/domains/{$ofB->getKey()}");

    $response->assertNotFound();

    // Stated separately and on purpose: a 403 here would be a functional
    // failure, not a cosmetic one.
    expect($response->status())->toBe(404)
        ->and($response->status())->not->toBe(403);
})->group('tenancy', 'fast');

it('answers identically for another tenant record and one that never existed', function (): void {
    // The real test of "does not confirm existence": the two responses must be
    // indistinguishable. Matching status codes are not enough if the bodies,
    // or the headers, differ.
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    $ofB = Tenancy::forTenant($b, fn (): TenantDomain => TenantDomain::factory()->create(['hostname' => 'theirs.example']));

    $key = keyFor($a);

    $existing = withHeader('Authorization', "Bearer {$key}")->getJson("/_iso/domains/{$ofB->getKey()}");
    $imaginary = withHeader('Authorization', "Bearer {$key}")->getJson('/_iso/domains/999999');

    expect($existing->status())->toBe($imaginary->status())
        ->and($existing->getContent())->toBe($imaginary->getContent());
})->group('tenancy', 'fast');

it('does not leak another tenant hostname in the response body', function (): void {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    $ofB = Tenancy::forTenant($b, fn (): TenantDomain => TenantDomain::factory()->create(['hostname' => 'secret-operator.example']));

    $response = withHeader('Authorization', 'Bearer ' . keyFor($a))
        ->getJson("/_iso/domains/{$ofB->getKey()}");

    expect($response->getContent())->not->toContain('secret-operator.example');
})->group('tenancy', 'fast');

it('does not let a key from one tenant read across after another tenant was resolved', function (): void {
    // Guards against context leaking between requests in the same process —
    // the kind of bug that only appears under a long-lived worker.
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    $ofA = Tenancy::forTenant($a, fn (): TenantDomain => TenantDomain::factory()->create(['hostname' => 'a.example']));
    $ofB = Tenancy::forTenant($b, fn (): TenantDomain => TenantDomain::factory()->create(['hostname' => 'b.example']));

    withHeader('Authorization', 'Bearer ' . keyFor($b))
        ->getJson("/_iso/domains/{$ofB->getKey()}")->assertOk();

    withHeader('Authorization', 'Bearer ' . keyFor($a))
        ->getJson("/_iso/domains/{$ofB->getKey()}")->assertNotFound();

    withHeader('Authorization', 'Bearer ' . keyFor($b))
        ->getJson("/_iso/domains/{$ofA->getKey()}")->assertNotFound();
})->group('tenancy', 'fast');
