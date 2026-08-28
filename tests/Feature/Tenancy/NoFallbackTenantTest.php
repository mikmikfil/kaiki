<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\get;
use function Pest\Laravel\withHeader;

/*
 * Spec SEC-4 / TEN-4: there is no default tenant and no fallback.
 *
 * ADR-0001 treats a fallback as a tenant-isolation defect, and it is easy to see
 * why: the alternative to "I do not know which operator this request is for" is
 * serving somebody else's data. 404 is also the right answer outward — it does
 * not disclose whether a slug or a hostname exists.
 *
 * These assertions live here rather than in #8 on purpose. #8 proves the scope
 * cannot leak *between* resolved tenants; this proves the system refuses to
 * proceed with *no* tenant at all, which is a different failure.
 */

beforeEach(function (): void {
    config()->set('kaiki.tenancy.hosted_host', 'book.kaiki.test');

    Route::middleware('tenant')->get('/_probe', fn () => response()->json(['tenant' => tenant()?->getKey()]));
    Route::middleware('tenant')->get('/{slug}', fn () => response()->json(['tenant' => tenant()?->getKey()]));
});

it('404s an unknown host', function (): void {
    Tenant::factory()->create();

    get('http://never-registered.example/_probe')->assertNotFound();
})->group('fast');

it('404s an unknown slug on the hosted host', function (): void {
    Tenant::factory()->create(['slug' => 'a-real-operator']);

    get('http://book.kaiki.test/not-an-operator')->assertNotFound();
})->group('fast');

it('404s a request with no key, no host match and no session', function (): void {
    Tenant::factory()->create();

    get('http://kaiki.test/_probe')->assertNotFound();
})->group('fast');

it('404s an invalid API key rather than falling through to another strategy', function (): void {
    // A bad key must not quietly degrade into "resolve by host instead". The
    // caller asked for a specific tenant and did not get it.
    Tenant::factory()->create(['slug' => 'a-real-operator']);

    withHeader('Authorization', 'Bearer pk_live_totallymadeupkeyvalue000000000000')
        ->get('http://book.kaiki.test/_probe')
        ->assertNotFound();
})->group('fast');

it('never resolves a tenant when one tenant exists and nothing matches', function (): void {
    // The tempting bug: "there is only one operator, so obviously they meant
    // that one". True on day one, catastrophic on the day a second signs up.
    $only = Tenant::factory()->create(['slug' => 'the-only-one']);

    get('http://kaiki.test/_probe')->assertNotFound();

    expect(Tenant::query()->count())->toBe(1)
        ->and($only->slug)->toBe('the-only-one');
})->group('fast');
