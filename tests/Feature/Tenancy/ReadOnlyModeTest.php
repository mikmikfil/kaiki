<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

/*
 * Spec TEN-9 / SAA-7: a lapsed subscription puts the tenant in read-only mode.
 *
 * The asymmetry is the design. Guests keep seeing trips, existing bookings keep
 * working, token pages keep opening — what stops is the operator changing
 * anything. The pressure lands on the person who owes money, not on a tourist
 * holding a ticket for tomorrow morning.
 */

beforeEach(function (): void {
    config()->set('kaiki.tenancy.hosted_host', 'book.kaiki.test');

    // `_probe` rather than the bare slug: #101 put a **real** hosted page at
    // `book.{host}/{slug}`, and a probe sharing that path stopped testing the
    // middleware and started testing which route Laravel matched first. The
    // leading underscore is outside the slug pattern those routes are
    // constrained to, so the two cannot collide again.
    //
    // The tenant still resolves from segment 1, which is what these tests are
    // about — `_probe` is simply not a slug any operator can hold.
    Route::middleware(['tenant', 'tenant.writable'])
        ->match(['get', 'post'], '/{slug}/_probe', fn () => response()->json(['ok' => true]));
});

function readOnlyTenant(): Tenant
{
    return Tenant::factory()->create(['slug' => 'lapsed', 'status' => TenantStatus::ReadOnly]);
}

it('refuses a write for a read-only tenant', function (): void {
    readOnlyTenant();

    postJson('http://book.kaiki.test/lapsed/_probe')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'tenant_read_only');
})->group('fast');

it('still serves reads for a read-only tenant', function (): void {
    // The whole point: the operator's trips stay visible while their account is
    // in arrears, so guests are never punished for a billing problem.
    readOnlyTenant();

    getJson('http://book.kaiki.test/lapsed/_probe')->assertOk()->assertJson(['ok' => true]);
})->group('fast');

it('allows writes for an active tenant', function (): void {
    Tenant::factory()->create(['slug' => 'paying', 'status' => TenantStatus::Active]);

    postJson('http://book.kaiki.test/paying/_probe')->assertOk();
})->group('fast');

it('allows writes while a payment is merely overdue', function (): void {
    // past_due is the dunning window, not the punishment. Cutting an operator
    // off the moment a card fails would break bookings over a payment that
    // usually succeeds on retry.
    Tenant::factory()->create(['slug' => 'chasing', 'status' => TenantStatus::PastDue]);

    postJson('http://book.kaiki.test/chasing/_probe')->assertOk();
})->group('fast');

it('refuses writes for a suspended tenant', function (): void {
    Tenant::factory()->create(['slug' => 'gone', 'status' => TenantStatus::Suspended]);

    postJson('http://book.kaiki.test/gone/_probe')->assertStatus(403);
})->group('fast');

it('explains the refusal in both Greek and English', function (): void {
    readOnlyTenant();

    $response = postJson('http://book.kaiki.test/lapsed/_probe')->assertStatus(403);

    expect($response->json('error.message'))->toContain('read only')
        ->and($response->json('error.message_el'))->toContain('μόνο για ανάγνωση')
        ->and($response->json('error.message'))->not->toBe($response->json('error.message_el'));
})->group('fast');

it('refuses a browser write with a localised message rather than JSON', function (): void {
    // An operator clicking Save in the panel gets a readable sentence, not a
    // JSON body they will never see.
    readOnlyTenant();

    post('http://book.kaiki.test/lapsed/_probe')->assertStatus(403);
})->group('fast');

it('never blocks a safe method', function (): void {
    readOnlyTenant();

    get('http://book.kaiki.test/lapsed/_probe')->assertOk();
})->group('fast');
