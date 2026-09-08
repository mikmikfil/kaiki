<?php

declare(strict_types=1);

use App\Domain\Webhooks\Actions\DispatchWebhookEvent;
use App\Domain\Webhooks\EventRegistry;
use App\Domain\Webhooks\Support\SafeUrl;
use App\Domain\Webhooks\Support\WebhookPayload;
use App\Domain\Webhooks\Support\WebhookSignature;
use App\Enums\BookingSource;
use App\Enums\CancelReason;
use App\Enums\DeliveryStatus;
use App\Enums\WebhookEvent;
use App\Events\BookingCancelled;
use App\Events\BookingConfirmed;
use App\Events\DepartureCancelled;
use App\Events\GuestDetailsCompleted;
use App\Jobs\DeliverWebhook;
use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\Departure;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| #125 — outbound webhooks (OPS-19, OPS-20, docs/api.md §8)
|--------------------------------------------------------------------------
|
| A webhook is a promise made to somebody else's software, and the ways of
| breaking it are not equally visible. A delivery that never arrives is noticed
| within a day. A delivery that arrives **twice** silently double-invoices
| somebody, and a delivery that arrives with a document number in it is a data
| breach nobody sees at all.
|
| So these tests are weighted towards the invisible failures: the unique index
| that makes a replayed job a no-op, the two sources that must never fire, and
| the payload asserted from **outside** — a real document number in the
| database, and a search of the serialised bytes for it.
|
*/

/** @param  array<string, mixed>  $attributes */
function endpointFor(Tenant $tenant, array $attributes = []): WebhookEndpoint
{
    return Tenancy::forTenant($tenant, fn (): WebhookEndpoint => WebhookEndpoint::factory()->create($attributes));
}

it('writes one delivery per subscribed endpoint and queues each', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();

    $wants = endpointFor($tenant);
    $doesNot = endpointFor($tenant, ['events' => [WebhookEvent::DepartureCancelled->value]]);

    $queued = app(DispatchWebhookEvent::class)(
        $tenant,
        WebhookEvent::BookingConfirmed,
        ['booking' => ['uuid' => 'x']],
    );

    expect($queued)->toBe(1);

    Tenancy::forTenant($tenant, function () use ($wants, $doesNot): void {
        expect(WebhookDelivery::query()->where('webhook_endpoint_id', $wants->getKey())->count())->toBe(1)
            ->and(WebhookDelivery::query()->where('webhook_endpoint_id', $doesNot->getKey())->count())->toBe(0);
    });

    Queue::assertPushed(DeliverWebhook::class, 1);
});

it('gives each endpoint its own delivery id', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    endpointFor($tenant);
    endpointFor($tenant);

    app(DispatchWebhookEvent::class)($tenant, WebhookEvent::BookingConfirmed, []);

    // `Kaiki-Delivery-Id` is the receiver's idempotency key. Two endpoints
    // belonging to two different systems must not be told to deduplicate
    // against each other's id.
    $ids = Tenancy::forTenant($tenant, fn (): array => WebhookDelivery::query()->pluck('event_id')->all());

    expect($ids)->toHaveCount(2)
        ->and(array_unique($ids))->toHaveCount(2);
});

it('sends nothing to an endpoint that has been switched off', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    endpointFor($tenant, ['is_active' => false, 'disabled_at' => now()]);

    expect(app(DispatchWebhookEvent::class)($tenant, WebhookEvent::BookingConfirmed, []))->toBe(0);

    Queue::assertNothingPushed();
});

it('refuses a second delivery for the same event and endpoint', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $endpoint = endpointFor($tenant);

    Tenancy::forTenant($tenant, function () use ($endpoint): void {
        $row = [
            'webhook_endpoint_id' => $endpoint->getKey(),
            'event' => WebhookEvent::BookingConfirmed,
            'event_id' => 'fixed-event-id',
            'payload' => [],
            'status' => DeliveryStatus::Pending,
        ];

        WebhookDelivery::query()->create($row);

        // The unique index is the delivery guarantee. A worker that died after
        // the POST and before the ack comes back and tries again; the database
        // is what stops a second charge notification reaching somebody's
        // accounting system.
        expect(fn () => WebhookDelivery::query()->create($row))->toThrow(QueryException::class);
    });
});

it('never sends a webhook for an imported booking', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    endpointFor($tenant);

    $booking = Tenancy::forTenant($tenant, fn () => Booking::factory()->create([
        'source' => BookingSource::Import,
    ]));

    // BKG-34. Importing four seasons from WooCommerce must not post four
    // seasons of `booking.confirmed` at somebody's accounting system.
    expect(app(DispatchWebhookEvent::class)->forBooking($booking, WebhookEvent::BookingConfirmed))->toBe(0);

    Queue::assertNothingPushed();
});

it('sends a test booking, flagged rather than suppressed', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    endpointFor($tenant);

    $booking = Tenancy::forTenant($tenant, fn () => Booking::factory()->create(['is_test' => true]));

    // SAA-12 keeps test bookings out of every figure and export. Webhooks are
    // the one place they are *sent*, because an operator wiring up their
    // integration needs the event to arrive — so the flag rides along and §8.2
    // tells consumers to branch on it.
    expect(app(DispatchWebhookEvent::class)->forBooking($booking, WebhookEvent::BookingConfirmed))->toBe(1);

    $payload = Tenancy::forTenant($tenant, fn () => WebhookDelivery::query()->firstOrFail()->payload);

    expect($payload['is_test'])->toBeTrue();
});

it('carries no document number, asserted against the bytes', function (): void {
    $tenant = Tenant::factory()->create();

    $number = 'AB1234567';

    $booking = Tenancy::forTenant($tenant, function () use ($number) {
        $booking = Booking::factory()->create();

        BookingGuest::factory()->for($booking)->create([
            'full_name' => 'Μαρία Παπαδοπούλου',
            'document_number' => $number,
        ]);

        return $booking->fresh();
    });

    $serialised = (string) json_encode(
        Tenancy::forTenant($tenant, fn (): array => WebhookPayload::guestDetails($booking, 1)),
    );

    // Asserted from outside, the way `ExportRows` is: a real number in the
    // database and a search of the finished bytes. A test that checked the
    // array keys would pass against a payload that leaked the number inside a
    // nested structure nobody thought to look at.
    expect($serialised)->not->toContain($number)
        ->and($serialised)->toContain('"complete":true');
});

it('leaves the guest\'s own credentials out of the payload', function (): void {
    $tenant = Tenant::factory()->create();

    $booking = Tenancy::forTenant($tenant, fn () => Booking::factory()->create());

    $payload = Tenancy::forTenant($tenant, fn (): array => WebhookPayload::booking($booking));

    // `manage_token` cancels the booking and `links` are the guest's pages.
    // Neither is the integrator's to hold (§8.2).
    expect($payload['booking'])->not->toHaveKey('manage_token')
        ->and($payload['booking'])->not->toHaveKey('links')
        ->and($payload['booking']['uuid'])->toBe($booking->uuid);
});

/*
|--------------------------------------------------------------------------
| The signature
|--------------------------------------------------------------------------
*/

it('signs the timestamp and the body together', function (): void {
    $signature = WebhookSignature::sign('secret', 1785312062, '{"a":1}');

    expect($signature)->toStartWith('v1=')
        // A signature over the body alone is replayable for ever: capture one
        // delivery, post it again next month, and it verifies.
        ->and($signature)->not->toBe(WebhookSignature::sign('secret', 1785312063, '{"a":1}'));
});

it('accepts either signature during a rotation', function (): void {
    $header = WebhookSignature::header(['new-secret', 'old-secret'], 1785312062, 'body');

    expect(WebhookSignature::verify('new-secret', $header, 1785312062, 'body', 1785312062))->toBeTrue()
        ->and(WebhookSignature::verify('old-secret', $header, 1785312062, 'body', 1785312062))->toBeTrue()
        ->and(WebhookSignature::verify('third-secret', $header, 1785312062, 'body', 1785312062))->toBeFalse();
});

it('refuses a signature whose timestamp has aged out', function (): void {
    $header = WebhookSignature::header(['secret'], 1785312062, 'body');

    // Valid signature, stale clock. §8.3 bounds replay at five minutes.
    expect(WebhookSignature::verify('secret', $header, 1785312062, 'body', 1785312062 + 301))->toBeFalse()
        ->and(WebhookSignature::verify('secret', $header, 1785312062, 'body', 1785312062 + 299))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The SSRF guard
|--------------------------------------------------------------------------
*/

it('refuses every address a webhook may not reach', function (string $url): void {
    expect(SafeUrl::isAllowed($url, resolve: false))->toBeFalse();
})->with([
    'plain http' => 'http://example.com/hook',
    'loopback' => 'https://127.0.0.1/hook',
    'the cloud metadata service' => 'https://169.254.169.254/latest/meta-data/',
    'a private range' => 'https://10.0.0.5/hook',
    'another private range' => 'https://192.168.1.1/hook',
    'ipv6 loopback' => 'https://[::1]/hook',
    'not a url at all' => 'not-a-url',
]);

it('allows an ordinary https endpoint', function (): void {
    expect(SafeUrl::isAllowed('https://hooks.example.com/kaiki', resolve: false))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Delivery, retries and the twenty-failure rule
|--------------------------------------------------------------------------
*/

it('marks a delivery delivered on a 2xx and resets the failure count', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    $tenant = Tenant::factory()->create();
    $endpoint = endpointFor($tenant, ['consecutive_failures' => 5]);

    $delivery = Tenancy::forTenant($tenant, fn () => WebhookDelivery::factory()->create([
        'webhook_endpoint_id' => $endpoint->getKey(),
    ]));

    app(DeliverWebhook::class, ['deliveryId' => $delivery->getKey()])->handle(app('Illuminate\Http\Client\Factory'));

    $delivery->refresh();
    $endpoint->refresh();

    expect($delivery->status)->toBe(DeliveryStatus::Delivered)
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->delivered_at)->not->toBeNull()
        // An endpoint that fails nineteen times and then works has a clean
        // slate: the rule is about a dead URL, not a rough afternoon.
        ->and($endpoint->consecutive_failures)->toBe(0);
});

it('schedules the next attempt on a failure rather than giving up', function (): void {
    Http::fake(['*' => Http::response('nope', 500)]);
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $endpoint = endpointFor($tenant);

    $delivery = Tenancy::forTenant($tenant, fn () => WebhookDelivery::factory()->create([
        'webhook_endpoint_id' => $endpoint->getKey(),
    ]));

    app(DeliverWebhook::class, ['deliveryId' => $delivery->getKey()])->handle(app('Illuminate\Http\Client\Factory'));

    $delivery->refresh();

    expect($delivery->status)->toBe(DeliveryStatus::Pending)
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->response_status)->toBe(500)
        ->and($delivery->next_attempt_at)->not->toBeNull();

    Queue::assertPushed(DeliverWebhook::class);
});

it('gives up after the published number of attempts', function (): void {
    Http::fake(['*' => Http::response('nope', 500)]);
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $endpoint = endpointFor($tenant);

    $delivery = Tenancy::forTenant($tenant, fn () => WebhookDelivery::factory()->create([
        'webhook_endpoint_id' => $endpoint->getKey(),
        // One short of the eight of §8.4.
        'attempts' => WebhookDelivery::maxAttempts() - 1,
    ]));

    app(DeliverWebhook::class, ['deliveryId' => $delivery->getKey()])->handle(app('Illuminate\Http\Client\Factory'));

    $delivery->refresh();

    expect($delivery->status)->toBe(DeliveryStatus::Failed)
        ->and($delivery->next_attempt_at)->toBeNull();

    // Re-sendable by hand from the panel, but never again on its own.
    Queue::assertNotPushed(DeliverWebhook::class);
});

it('switches an endpoint off after twenty consecutive failures', function (): void {
    Http::fake(['*' => Http::response('gone', 410)]);

    $tenant = Tenant::factory()->create();
    $endpoint = endpointFor($tenant, ['consecutive_failures' => WebhookEndpoint::FAILURE_LIMIT - 1]);

    $delivery = Tenancy::forTenant($tenant, fn () => WebhookDelivery::factory()->create([
        'webhook_endpoint_id' => $endpoint->getKey(),
        'attempts' => WebhookDelivery::maxAttempts() - 1,
    ]));

    app(DeliverWebhook::class, ['deliveryId' => $delivery->getKey()])->handle(app('Illuminate\Http\Client\Factory'));

    $endpoint->refresh();

    // §8.4: nobody's queue should burn for a week on a dead URL.
    expect($endpoint->is_active)->toBeFalse()
        ->and($endpoint->disabled_at)->not->toBeNull();
});

it('abandons a delivery whose endpoint went away, rather than failing it', function (): void {
    Http::fake();

    $tenant = Tenant::factory()->create();
    $endpoint = endpointFor($tenant);

    $delivery = Tenancy::forTenant($tenant, fn () => WebhookDelivery::factory()->create([
        'webhook_endpoint_id' => $endpoint->getKey(),
    ]));

    Tenancy::forTenant($tenant, fn () => $endpoint->delete());

    app(DeliverWebhook::class, ['deliveryId' => $delivery->getKey()])->handle(app('Illuminate\Http\Client\Factory'));

    // Nothing failed; there is nowhere to send it. `abandoned` keeps it out of
    // OPS-21's feed and off the retry button.
    expect($delivery->refresh()->status)->toBe(DeliveryStatus::Abandoned);

    Http::assertNothingSent();
});

it('sends the headers the contract publishes', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    $tenant = Tenant::factory()->create();
    $endpoint = endpointFor($tenant);

    $delivery = Tenancy::forTenant($tenant, fn () => WebhookDelivery::factory()->create([
        'webhook_endpoint_id' => $endpoint->getKey(),
    ]));

    app(DeliverWebhook::class, ['deliveryId' => $delivery->getKey()])->handle(app('Illuminate\Http\Client\Factory'));

    Http::assertSent(function ($request) use ($delivery, $endpoint): bool {
        $timestamp = (int) $request->header('Kaiki-Timestamp')[0];

        return $request->header('Kaiki-Event')[0] === $delivery->event->value
            && $request->header('Kaiki-Delivery-Id')[0] === $delivery->event_id
            && $request->header('Kaiki-Attempt')[0] === '1'
            && $request->header('User-Agent')[0] === 'Kaiki-Webhooks/1'
            // The signature is over the bytes actually sent, which is the whole
            // reason §8.3 tells consumers to read the raw body first.
            && WebhookSignature::verify(
                $endpoint->signing_secret,
                $request->header('Kaiki-Signature')[0],
                $timestamp,
                $request->body(),
                $timestamp,
            );
    });
});

it('never retries a delivery it has already made', function (): void {
    Http::fake();

    $tenant = Tenant::factory()->create();
    $endpoint = endpointFor($tenant);

    $delivery = Tenancy::forTenant($tenant, fn () => WebhookDelivery::factory()->delivered()->create([
        'webhook_endpoint_id' => $endpoint->getKey(),
    ]));

    app(DeliverWebhook::class, ['deliveryId' => $delivery->getKey()])->handle(app('Illuminate\Http\Client\Factory'));

    // The duplicate job a dead worker leaves behind. At-least-once is what a
    // queue offers; this is the other half.
    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| The event registry
|--------------------------------------------------------------------------
*/

it('drops an event name it will never send', function (): void {
    // Refused at the form (`accepts`), dropped on read (`normalise`) — the two
    // are different on purpose: a typo somebody can still fix should be
    // reported, and a row written against a withdrawn name should render.
    expect(EventRegistry::accepts('booking.confirmd'))->toBeFalse()
        ->and(EventRegistry::normalise(['booking.confirmed', 'nonsense']))->toBe(['booking.confirmed']);
});

it('stores the subscription list in a stable order', function (): void {
    $tenant = Tenant::factory()->create();

    $endpoint = endpointFor($tenant, [
        'events' => ['departure.cancelled', 'booking.confirmed', 'booking.confirmed'],
    ]);

    // Duplicates removed and declaration order restored, so two endpoints
    // subscribed to the same events compare equal however the boxes were
    // ticked.
    expect($endpoint->refresh()->events)->toBe(['booking.confirmed', 'departure.cancelled']);
});

it('shows one operator nothing of another\'s deliveries', function (): void {
    Queue::fake();

    $mine = Tenant::factory()->create();
    $theirs = Tenant::factory()->create();

    endpointFor($mine);
    endpointFor($theirs);

    app(DispatchWebhookEvent::class)($theirs, WebhookEvent::BookingConfirmed, []);

    expect(Tenancy::forTenant($mine, fn (): int => WebhookDelivery::query()->count()))->toBe(0)
        ->and(Tenancy::forTenant($theirs, fn (): int => WebhookDelivery::query()->count()))->toBe(1);
});

it('spaces the retries out the way the contract says', function (): void {
    Carbon::setTestNow('2026-09-08 10:00:00');

    $delivery = new WebhookDelivery;

    foreach ([0 => 10, 1 => 30, 2 => 120, 7 => 43200] as $attempts => $seconds) {
        $delivery->attempts = $attempts;

        $gap = Carbon::now()->diffInSeconds($delivery->nextAttemptAt());

        // Jitter is added, never subtracted: a delivery is never retried
        // sooner than the published schedule, which is the half a receiver's
        // own rate limiter cares about.
        expect($gap)->toBeGreaterThanOrEqual($seconds)
            ->and($gap)->toBeLessThanOrEqual($seconds + intdiv($seconds, 10) + 1);
    }
});

/*
|--------------------------------------------------------------------------
| The wiring — that the four events actually reach the dispatcher
|--------------------------------------------------------------------------
|
| The tests above prove the machinery works when called. This proves it is
| called, which is the failure the rest could not catch: a listener registered
| against three events instead of four ships a product where one webhook
| silently never fires, and every unit test still passes.
|
*/

/** The event a fired domain event turned into, or null if it turned into nothing. */
function deliveredEvent(Tenant $tenant): ?WebhookEvent
{
    return Tenancy::forTenant($tenant, fn (): ?WebhookEvent => WebhookDelivery::query()->first()?->event);
}

it('publishes booking.confirmed', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    endpointFor($tenant);
    $booking = Tenancy::forTenant($tenant, fn () => Booking::factory()->create());

    event(new BookingConfirmed($booking->getKey(), $tenant->getKey()));

    expect(deliveredEvent($tenant))->toBe(WebhookEvent::BookingConfirmed);
});

it('publishes booking.cancelled', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    endpointFor($tenant);
    $booking = Tenancy::forTenant($tenant, fn () => Booking::factory()->create());

    event(new BookingCancelled($booking->getKey(), $tenant->getKey(), CancelReason::GuestRequest, 0));

    expect(deliveredEvent($tenant))->toBe(WebhookEvent::BookingCancelled);
});

it('publishes guest_details.completed', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    endpointFor($tenant);
    $booking = Tenancy::forTenant($tenant, fn () => Booking::factory()->create());

    event(new GuestDetailsCompleted($booking->getKey(), $tenant->getKey(), 2));

    expect(deliveredEvent($tenant))->toBe(WebhookEvent::GuestDetailsCompleted);
});

it('publishes departure.cancelled', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    endpointFor($tenant);
    $departure = Tenancy::forTenant($tenant, fn () => Departure::factory()->create());

    event(new DepartureCancelled($departure));

    expect(deliveredEvent($tenant))->toBe(WebhookEvent::DepartureCancelled);
});

it('fires the guest-details event on the transition and not on every save', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    endpointFor($tenant);

    $booking = Tenancy::forTenant($tenant, fn () => Booking::factory()->create());

    // Two identical announcements of the same finished manifest. `syncStatus`
    // runs on every save of the guest form, so a party filled in over three
    // sittings would otherwise post three times — and the receiver would have
    // no way to tell the repeats apart, because they are genuinely different
    // events with different ids.
    event(new GuestDetailsCompleted($booking->getKey(), $tenant->getKey(), 2));
    event(new GuestDetailsCompleted($booking->getKey(), $tenant->getKey(), 2));

    // Two events *were* fired here, so two rows is correct — the guard against
    // the repeat lives in `SaveGuestDetails`, which only dispatches on the
    // change. This asserts the dispatcher does not silently collapse them,
    // which would hide that bug rather than prevent it.
    expect(Tenancy::forTenant($tenant, fn (): int => WebhookDelivery::query()->count()))->toBe(2);
});
