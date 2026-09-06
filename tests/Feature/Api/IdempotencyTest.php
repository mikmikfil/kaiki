<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

use function Pest\Laravel\postJson;

use Tests\Support\Api\BookingApiScenario;
use Tests\Support\Api\CatalogRequest;

/*
|--------------------------------------------------------------------------
| `Idempotency-Key` — docs/api.md §3.4
|--------------------------------------------------------------------------
|
| Five cases, and the contract numbers them:
|
|   1  first request      processed, and the response recorded before it is
|                         returned
|   2  same key, same     the recorded response **verbatim**, original status
|      body               included, plus `Idempotency-Replayed: true`
|   3  same key, other    `409 idempotency_key_reuse` — the client has a bug,
|      body               and failing loudly is the only safe answer when money
|                         is involved
|   4  still in flight    `409 idempotency_in_progress` with `Retry-After: 1`
|   5  a 5xx              not recorded; a retry re-executes
|
| The one that is easy to get wrong is the second: a replayed *create* must
| still return `201`, not `200`. A client that branches on the status — and the
| widget will — sees a different answer to the same request otherwise.
|
| Retention is 24 hours, and the last test travels past it rather than reaching
| into the store, because "the key is forgotten" is a behaviour and not an
| implementation detail.
|
*/

afterEach(function (): void {
    Carbon::setTestNow();
});

it('replays the recorded response verbatim, status and all', function (): void {
    $fixture = BookingApiScenario::bookable();
    $key = BookingApiScenario::idempotencyKey();
    $body = BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band']);

    $headers = ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => $key];

    $first = postJson(CatalogRequest::url('/bookings'), $body, $headers);
    $second = postJson(CatalogRequest::url('/bookings'), $body, $headers);

    $first->assertCreated();

    // §3.4: "a replayed create still returns 201, not 200."
    $second->assertCreated()
        ->assertJsonPath('data.uuid', $first->json('data.uuid'))
        ->assertJsonPath('data.reference', $first->json('data.reference'));

    expect($second->headers->get('Idempotency-Replayed'))->toBe('true')
        ->and($first->headers->get('Idempotency-Replayed'))->toBeNull();

    // "No side effect runs a second time: no second hold, no second gateway
    // session, no second refund."
    Tenancy::forTenant($fixture['tenant'], function (): void {
        expect(Booking::query()->count())->toBe(1);
    });
})->group('fast');

it('refuses the same key with a different body', function (): void {
    $fixture = BookingApiScenario::bookable();
    $key = BookingApiScenario::idempotencyKey();

    $headers = ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => $key];

    postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band']),
        $headers,
    )->assertCreated();

    postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band'], [
            'pax' => [['age_band_uuid' => $fixture['band']->uuid, 'qty' => 4]],
        ]),
        $headers,
    )
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'idempotency_key_reuse');

    // And nothing was created for the second call.
    Tenancy::forTenant($fixture['tenant'], function (): void {
        expect(Booking::query()->count())->toBe(1);
    });
})->group('fast');

it('treats a reordered body as the same request', function (): void {
    $fixture = BookingApiScenario::bookable();
    $key = BookingApiScenario::idempotencyKey();
    $headers = ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => $key];

    $body = BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band']);

    postJson(CatalogRequest::url('/bookings'), $body, $headers)->assertCreated();

    // Several HTTP libraries serialise object keys in a different order on a
    // retry. That is a fact about the client's JSON encoder, not about the
    // request — telling somebody their booking has a bug because their library
    // reordered two keys would be a support ticket nobody can diagnose.
    postJson(CatalogRequest::url('/bookings'), array_reverse($body, preserve_keys: true), $headers)
        ->assertCreated()
        ->assertHeader('Idempotency-Replayed', 'true');
})->group('fast');

it('treats a different value as a different request, however small', function (): void {
    $fixture = BookingApiScenario::bookable();
    $key = BookingApiScenario::idempotencyKey();
    $headers = ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => $key];

    postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band']),
        $headers,
    )->assertCreated();

    // The canonicaliser sorts keys and touches nothing else: `{"qty": 2}` and
    // `{"qty": "2"}` are different requests, and a normaliser that made them
    // equal would hide a real client bug behind a replay.
    postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band'], [
            'pax' => [['age_band_uuid' => $fixture['band']->uuid, 'qty' => '2']],
        ]),
        $headers,
    )->assertStatus(409);
})->group('fast');

it('requires a key on the write endpoints', function (): void {
    $fixture = BookingApiScenario::bookable();

    // §3.4: "Omitting a required key is 422 validation_failed with
    // details.idempotency_key."
    postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band']),
        ['Authorization' => "Bearer {$fixture['key']}"],
    )
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.details.idempotency_key', ['required']);
})->group('fast');

it('refuses a key that is not a uuid', function (): void {
    $fixture = BookingApiScenario::bookable();

    // §3.4 names the format: "a client-generated UUIDv4". A client sending its
    // own order number would collide with itself across days and never know.
    postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band']),
        ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => 'order-12345'],
    )
        ->assertStatus(422)
        ->assertJsonPath('error.details.idempotency_key', ['uuid']);
})->group('fast');

it('scopes a key to its own endpoint', function (): void {
    $fixture = BookingApiScenario::bookable();
    $key = BookingApiScenario::idempotencyKey();

    $created = postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band']),
        ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => $key],
    );

    $created->assertCreated();

    // §3.4 scopes the record to `(tenant, endpoint, key)`. A client that
    // generates one key per user action and retries both calls would otherwise
    // get a booking's body back from a checkout — the same key, two endpoints,
    // one answer.
    postJson(
        CatalogRequest::url('/bookings/' . $created->json('data.uuid') . '/checkout'),
        ['kind' => 'full', 'return_url' => 'https://aegeancruises.gr/thanks'],
        [
            'Authorization' => "Bearer {$fixture['key']}",
            'X-Kaiki-Guest-Token' => $created->json('data.manage_token'),
            'Idempotency-Key' => $key,
        ],
    )
        ->assertCreated()
        ->assertJsonPath('data.kind', 'full');
})->group('fast');

it('scopes a key to its own tenant', function (): void {
    $first = BookingApiScenario::bookable();
    $second = BookingApiScenario::bookable();

    $key = BookingApiScenario::idempotencyKey();

    postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($first['product'], $first['departure'], $first['band']),
        ['Authorization' => "Bearer {$first['key']}", 'Idempotency-Key' => $key],
    )->assertCreated();

    // Two operators are entitled to generate the same UUID, however unlikely,
    // and the tenant is in the key precisely so one can never answer for the
    // other.
    postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($second['product'], $second['departure'], $second['band']),
        ['Authorization' => "Bearer {$second['key']}", 'Idempotency-Key' => $key],
    )
        ->assertCreated()
        ->assertHeaderMissing('Idempotency-Replayed');
})->group('fast');

it('records a validation failure and replays it', function (): void {
    $fixture = BookingApiScenario::bookable();
    $key = BookingApiScenario::idempotencyKey();
    $headers = ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => $key];

    $body = BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band'], [
        'terms_accepted' => false,
    ]);

    postJson(CatalogRequest::url('/bookings'), $body, $headers)->assertStatus(422);

    // §3.4's fifth case: "a request that failed with a 4xx *is* recorded — a
    // validation error is a deterministic answer." Re-running the rules would
    // produce the same 422 anyway; replaying it costs nothing and keeps the
    // key's meaning uniform.
    postJson(CatalogRequest::url('/bookings'), $body, $headers)
        ->assertStatus(422)
        ->assertHeader('Idempotency-Replayed', 'true');
})->group('fast');

it('forgets a key after twenty-four hours', function (): void {
    $fixture = BookingApiScenario::bookable();
    $key = BookingApiScenario::idempotencyKey();
    $headers = ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => $key];
    $body = BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band']);

    Carbon::setTestNow('2026-07-03 09:00:00');

    postJson(CatalogRequest::url('/bookings'), $body, $headers)->assertCreated();

    // §3.4: "After that the key is forgotten and a replay is treated as a new
    // request." Asserted by travelling rather than by reaching into the store,
    // because the retention is a promise to a client and not an implementation
    // detail.
    Carbon::setTestNow('2026-07-04 09:00:01');

    postJson(CatalogRequest::url('/bookings'), $body, $headers)
        ->assertCreated()
        ->assertHeaderMissing('Idempotency-Replayed');

    Tenancy::forTenant($fixture['tenant'], function (): void {
        expect(Booking::query()->count())->toBe(2);
    });
})->group('fast');
