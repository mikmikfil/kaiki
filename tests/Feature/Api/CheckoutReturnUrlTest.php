<?php

declare(strict_types=1);

use App\Models\Payment;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\postJson;

use Tests\Support\Api\BookingApiScenario;

/*
|--------------------------------------------------------------------------
| `return_url` on checkout — SEC-7, and issue 111
|--------------------------------------------------------------------------
|
| `docs/api.md` has said this since the contract was written: *"Must be an
| allowed origin for the key, or a hosted-page URL."* Until issue 111 the field
| was validated as a URL and then **dropped on the floor** — no gateway read it,
| so an unchecked value could not redirect anybody and nothing failed.
|
| The sandbox checkout page is the first thing that actually sends a browser
| there, which makes the check a precondition rather than an improvement: an
| unchecked `return_url` is an open redirect wearing a payment flow's clothes,
| and the guest arrives at it from a page they trusted the operator with.
|
| The allow-list is the key's CORS list (SEC-7) — the same list answering a
| second question, rather than a second list to keep in step. An **empty** list
| means any origin, which is what it already means for CORS and what the panel
| warns an operator about.
|
*/

/**
 * A booking at the point of checkout, with the key's origin list set.
 *
 * The `Origin` header travels with both requests, because a key that names
 * origins is a key `ApiKeyCors` refuses without one (SEC-7) — the browser would
 * always send it, and a fixture that did not would be testing the CORS
 * middleware by accident rather than the field under examination.
 *
 * @param  array<string, mixed>  $overrides
 * @param  list<string>  $origins
 * @return array{0: array<string, mixed>, 1: string, 2: TestResponse<JsonResponse>}
 */
function checkoutWith(array $overrides, array $origins = []): array
{
    $fixture = BookingApiScenario::bookable(allowedOrigins: $origins);

    $headers = ['Authorization' => "Bearer {$fixture['key']}"];

    if ($origins !== []) {
        $headers['Origin'] = $origins[0];
    }

    $created = postJson(
        '/api/v1/bookings',
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band']),
        array_merge($headers, ['Idempotency-Key' => BookingApiScenario::idempotencyKey()]),
    )->assertCreated();

    $uuid = (string) $created->json('data.uuid');

    $response = postJson(
        "/api/v1/bookings/{$uuid}/checkout",
        array_merge(['kind' => 'full', 'return_url' => 'https://aegean-blue.example/thanks'], $overrides),
        array_merge($headers, ['Idempotency-Key' => BookingApiScenario::idempotencyKey()]),
    );

    return [$fixture, $uuid, $response];
}

it('accepts any origin when the key names none, as CORS already does', function (): void {
    [, , $response] = checkoutWith([]);

    $response->assertCreated();
})->group('fast');

it('accepts a return address on an origin the key allows', function (): void {
    [, , $response] = checkoutWith(
        ['return_url' => 'https://aegean-blue.example/thanks'],
        origins: ['https://aegean-blue.example'],
    );

    $response->assertCreated();
})->group('fast');

it('refuses a return address on an origin the key does not allow', function (): void {
    // The open redirect this exists to prevent: the gateway would send the
    // guest to `evil.example` from a page they reached trusting the operator.
    [, , $response] = checkoutWith(
        ['return_url' => 'https://evil.example/collect'],
        origins: ['https://aegean-blue.example'],
    );

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
})->group('fast');

it('always allows a page we serve ourselves', function (): void {
    // A hosted page is on our own origin under our own security policy, and an
    // operator embedding the widget there has no reason to add it to a list.
    [, , $response] = checkoutWith(
        ['return_url' => 'https://' . config('kaiki.tenancy.hosted_host') . '/aegean-blue'],
        origins: ['https://aegean-blue.example'],
    );

    $response->assertCreated();
})->group('fast');

it('stores the address on the payment, because that is where a redirect reads it', function (): void {
    [$fixture, , $response] = checkoutWith(['return_url' => 'https://aegean-blue.example/thanks']);

    $response->assertCreated();

    Tenancy::forTenant($fixture['tenant'], function (): void {
        $payment = Payment::query()->latest('id')->firstOrFail();

        expect($payment->return_url)->toBe('https://aegean-blue.example/thanks');
    });
})->group('fast');
