<?php

declare(strict_types=1);

use App\Enums\ApiKeyType;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

use Tests\Support\Api\BookingApiScenario;
use Tests\Support\Api\CatalogRequest;

/*
|--------------------------------------------------------------------------
| The first endpoints in the product where a `pk_` is refused — §2.1, §2.2
|--------------------------------------------------------------------------
|
| Every SEC-5 test so far has proved a publishable key *can* do something. This
| file proves one cannot, and §2.1 gives the reason:
|
| > A `uuid` alone never authorises anything.
|
| A `pk_` sits in the source of somebody's home page. If it could read a booking
| by uuid, then every uuid that ever appeared in a redirect URL, an analytics
| payload or a browser history would be a guest's name, phone number and
| itinerary.
|
| Checkout is the exception, and the exception has a **state** in it —
| footnote 1: a `pk_` may finish the flow it started while the booking is still
| a live `draft`, and not one moment later. Both halves are asserted, because an
| implementation that got only the first half would pass a test that only tried
| the happy path.
|
*/

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @return array{fixture: array<string, mixed>, uuid: string, token: string} */
function draftBooking(): array
{
    $fixture = BookingApiScenario::bookable();

    $created = postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band']),
        ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => BookingApiScenario::idempotencyKey()],
    );

    $created->assertCreated();

    return [
        'fixture' => $fixture,
        'uuid' => (string) $created->json('data.uuid'),
        'token' => (string) $created->json('data.manage_token'),
    ];
}

it('refuses a publishable key on the booking read', function (): void {
    ['fixture' => $fixture, 'uuid' => $uuid] = draftBooking();

    // The key just created this booking. It still cannot read it back without
    // the guest's own token, because the two are different claims: "I am this
    // website" and "I am this guest".
    getJson(
        CatalogRequest::url("/bookings/{$uuid}"),
        ['Authorization' => "Bearer {$fixture['key']}"],
    )
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden')
        ->assertJsonPath('error.details.required_credential', 'manage_token');
})->group('fast');

it('accepts the guest token on the booking read', function (): void {
    ['fixture' => $fixture, 'uuid' => $uuid, 'token' => $token] = draftBooking();

    getJson(
        CatalogRequest::url("/bookings/{$uuid}"),
        ['Authorization' => "Bearer {$fixture['key']}", 'X-Kaiki-Guest-Token' => $token],
    )->assertOk()->assertJsonPath('data.uuid', $uuid);
})->group('fast');

it('accepts a publishable key on checkout while the booking is a live draft', function (): void {
    ['fixture' => $fixture, 'uuid' => $uuid] = draftBooking();

    // §2.1 footnote 1: "that is the widget completing the flow it started in
    // the same session."
    postJson(
        CatalogRequest::url("/bookings/{$uuid}/checkout"),
        ['kind' => 'full', 'return_url' => 'https://aegeancruises.gr/thanks'],
        ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => BookingApiScenario::idempotencyKey()],
    )->assertCreated();
})->group('fast');

it('refuses a publishable key on checkout once the booking is confirmed', function (): void {
    ['fixture' => $fixture, 'uuid' => $uuid] = draftBooking();

    $booking = Tenancy::forTenant(
        $fixture['tenant'],
        fn (): Booking => Booking::query()->where('uuid', $uuid)->sole(),
    );

    BookingApiScenario::confirm($fixture['tenant'], $booking, 6500);

    // Footnote 1's other half: "Once the booking is `confirmed` (paying a
    // balance) the `pk_` is rejected and a `manage_token` is required, because
    // at that point the caller is claiming to be a specific guest, not a
    // specific website."
    postJson(
        CatalogRequest::url("/bookings/{$uuid}/checkout"),
        ['kind' => 'balance', 'return_url' => 'https://aegeancruises.gr/thanks'],
        ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => BookingApiScenario::idempotencyKey()],
    )
        ->assertForbidden()
        ->assertJsonPath('error.details.required_credential', 'manage_token');
})->group('fast');

it('refuses a publishable key on checkout once the hold has run out', function (): void {
    Carbon::setTestNow('2026-07-03 09:00:00');

    ['fixture' => $fixture, 'uuid' => $uuid] = draftBooking();

    // "still `draft` **with an unexpired hold**". A draft whose hold ran out is
    // a booking the widget's session no longer owns — somebody who left a tab
    // open over lunch, and possibly somebody else's tab entirely.
    Carbon::setTestNow('2026-07-03 10:00:00');

    postJson(
        CatalogRequest::url("/bookings/{$uuid}/checkout"),
        ['kind' => 'full', 'return_url' => 'https://aegeancruises.gr/thanks'],
        ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => BookingApiScenario::idempotencyKey()],
    )->assertForbidden();
})->group('fast');

it('refuses a publishable key on cancel, always', function (): void {
    ['fixture' => $fixture, 'uuid' => $uuid] = draftBooking();

    // §2.1's table gives cancel to a `manage_token` or an `sk_` and to nothing
    // else, with no footnote. A publishable key that could cancel a booking by
    // uuid would be a refund anybody with a browser could trigger.
    postJson(
        CatalogRequest::url("/bookings/{$uuid}/cancel"),
        ['dry_run' => true],
        ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => BookingApiScenario::idempotencyKey()],
    )->assertForbidden();
})->group('fast');

it('refuses a token that belongs to a different booking', function (): void {
    $first = draftBooking();
    $second = draftBooking();

    // A valid credential on the wrong path. Answering about the token's own
    // booking would be worse than refusing: the caller asked about one booking
    // and would be shown another, and every later assertion the client makes
    // would be about the wrong trip.
    getJson(
        CatalogRequest::url('/bookings/' . $second['uuid']),
        [
            'Authorization' => "Bearer {$first['fixture']['key']}",
            'X-Kaiki-Guest-Token' => $first['token'],
        ],
    )->assertNotFound();
})->group('fast');

it('answers the same for an unknown uuid and an unknown token', function (): void {
    ['fixture' => $fixture, 'uuid' => $uuid] = draftBooking();

    $unknownBooking = getJson(
        CatalogRequest::url('/bookings/00000000-0000-4000-8000-000000000000'),
        ['Authorization' => "Bearer {$fixture['key']}", 'X-Kaiki-Guest-Token' => str_repeat('a', 40)],
    );

    $unknownToken = getJson(
        CatalogRequest::url("/bookings/{$uuid}"),
        ['Authorization' => "Bearer {$fixture['key']}", 'X-Kaiki-Guest-Token' => str_repeat('b', 40)],
    );

    // TOK-4's rule, which applies to the API just as much as to the guest
    // pages: never a distinction between "not found" and "wrong credential".
    // A different answer would let somebody holding a uuid learn whether it
    // exists.
    $unknownBooking->assertNotFound();
    $unknownToken->assertNotFound();

    expect($unknownBooking->json('error.code'))->toBe($unknownToken->json('error.code'))
        ->and($unknownBooking->getContent())->toBe($unknownToken->getContent());
})->group('fast');

it('refuses a booking from another tenant even with a valid key', function (): void {
    $mine = draftBooking();
    $theirs = draftBooking();

    // The uuid is real and the token is real — they simply belong to somebody
    // else's fleet, and the API key resolved a different tenant.
    //
    // The guest **pages** resolve a `manage_token` without tenancy, because
    // `/b/{token}` has no tenant until the token supplies one. The API already
    // has one, so the lookup is scoped — which is stricter, and also the only
    // way to avoid rendering another operator's booking inside this tenant,
    // where #8's global scope would hide its own product and vessel and produce
    // a payload of nulls that reads like a bug in the resource.
    getJson(
        CatalogRequest::url('/bookings/' . $theirs['uuid']),
        [
            'Authorization' => "Bearer {$mine['fixture']['key']}",
            'X-Kaiki-Guest-Token' => $theirs['token'],
        ],
    )->assertNotFound();
})->group('fast');

it('lets a secret key act on any of its own bookings', function (): void {
    $fixture = BookingApiScenario::bookable(keyType: ApiKeyType::Secret);

    $created = postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band']),
        ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => BookingApiScenario::idempotencyKey()],
    );

    $uuid = (string) $created->json('data.uuid');

    // §2.1's table: an `sk_` is server-side by definition, so the reason a
    // `pk_` is refused — it is visible in a page's source — does not apply.
    getJson(CatalogRequest::url("/bookings/{$uuid}"), ['Authorization' => "Bearer {$fixture['key']}"])
        ->assertOk();

    postJson(
        CatalogRequest::url("/bookings/{$uuid}/checkout"),
        ['kind' => 'full', 'return_url' => 'https://aegeancruises.gr/thanks'],
        ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => BookingApiScenario::idempotencyKey()],
    )->assertCreated();

    Tenancy::forTenant($fixture['tenant'], function () use ($uuid): void {
        expect(Booking::query()->where('uuid', $uuid)->sole()->status)->toBe(BookingStatus::PendingPayment);
    });
})->group('fast');
