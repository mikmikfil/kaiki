<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\WebhookEventStatus;
use App\Models\Booking;
use App\Models\GatewayWebhookEvent;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Log;

use function Pest\Laravel\postJson;

use Tests\Support\Payments\WebhookScenario;

/*
|--------------------------------------------------------------------------
| PAY-6 and PAY-7: verified before parsed, refused and recorded
|--------------------------------------------------------------------------
|
| PAY-6: *"verify signature before any parsing."* The order is the requirement —
| a payload parsed before it is verified is a payload an attacker chose, fed to
| our JSON decoder and then to whatever reads it.
|
| PAY-7: an unverified webhook is *rejected 400, logged with the source IP, and
| rate-limited*. Refusing without recording means a stream of forged webhooks
| from one address is invisible, and the IP is the only thing that makes it
| investigable.
|
*/

it('refuses an unsigned webhook with 400', function (): void {
    [$tenant] = WebhookScenario::make();

    postJson('/webhooks/stripe', WebhookScenario::stripeSuccess('evt_unsigned'))
        ->assertStatus(400)
        ->assertJson(['received' => false]);

    Tenancy::forTenant($tenant, function (): void {
        expect(Booking::query()->first()?->status)->toBe(BookingStatus::PendingPayment);
    });
})->group('fast');

it('refuses a wrongly signed webhook with 400', function (): void {
    WebhookScenario::make();

    $payload = WebhookScenario::stripeSuccess('evt_wrong_secret');

    postJson('/webhooks/stripe', $payload, WebhookScenario::signedStripeHeaders($payload, 'whsec_someone_elses_secret'))
        ->assertStatus(400);
})->group('fast');

it('refuses a valid signature over an old payload, because that is a replay', function (): void {
    WebhookScenario::make();

    $payload = WebhookScenario::stripeSuccess('evt_stale');
    $body = (string) json_encode($payload);
    // Genuinely signed with the right secret — and an hour old. A verifier that
    // only compares the HMAC accepts this forever, which is what the timestamp
    // tolerance exists to stop.
    $old = (string) (time() - 3600);

    postJson('/webhooks/stripe', $payload, [
        'Stripe-Signature' => 't=' . $old . ',v1=' . hash_hmac('sha256', $old . '.' . $body, WebhookScenario::SECRET),
    ])->assertStatus(400);
})->group('fast');

it('does not parse an unverified payload', function (): void {
    WebhookScenario::make();

    // A payload whose *contents* would be acted on if they were read: an event
    // type that confirms, and a reference matching a real payment. If the
    // endpoint parsed before verifying, this would confirm a booking nobody
    // paid for.
    postJson('/webhooks/stripe', WebhookScenario::stripeSuccess('evt_forged'))->assertStatus(400);

    // **Not looked up by `evt_forged`**, and that is the finding rather than a
    // workaround. The endpoint never parsed the body, so it never saw the id
    // inside it — the row carries a synthetic `unidentified:` id instead. An
    // unverified webhook cannot contribute *anything* from its payload,
    // including the key we would otherwise file it under, which is precisely
    // what "verify before parsing" costs and precisely what it buys.
    $event = GatewayWebhookEvent::query()->where('signature_valid', false)->sole();

    // Recorded — PAY-7 asks for it — but with an **empty payload**, which is
    // the assertion that matters: the body was never decoded into anything the
    // application then reasoned about.
    expect($event->event_id)->toStartWith('unidentified:')
        ->and($event->event_id)->not->toContain('evt_forged')
        ->and($event->payload)->toBe([])
        ->and($event->status)->toBe(WebhookEventStatus::Ignored);
})->group('fast');

it('records an unverified webhook with its source IP in the log', function (): void {
    // The spy is asserted on directly rather than through the facade:
    // `Log::shouldHaveReceived()` resolves at runtime but is not statically
    // visible, the same shape as the Pest helpers in #80 and #81.
    $log = Log::spy();

    WebhookScenario::make();

    postJson('/webhooks/stripe', WebhookScenario::stripeSuccess('evt_logged'))->assertStatus(400);

    // PAY-7. The IP is what makes a forged stream investigable, and it is not
    // personal data about a guest — it is the address of whoever is probing.
    $log->shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'payments.webhook_unverified'
            && array_key_exists('ip', $context));
})->group('fast');

it('refuses a provider that has no webhooks at all', function (): void {
    // `cash` and `bank_transfer` are how a manual booking is recorded as paid
    // (BKG-33). A request naming one is not a routing accident; it is somebody
    // probing the shape of the endpoint.
    postJson('/webhooks/cash', ['id' => 'evt_x'])->assertStatus(404);
    postJson('/webhooks/nonsense', ['id' => 'evt_x'])->assertStatus(404);

    expect(GatewayWebhookEvent::query()->count())->toBe(0);
})->group('fast');

it('gives every unverified request its own row rather than deduplicating them', function (): void {
    WebhookScenario::make();

    // Three probes with no id at all. Under a shared synthetic id the second
    // and third would hit the unique index and vanish — and a forged *stream*,
    // which is the thing PAY-7 wants visible, would look like one event.
    for ($i = 0; $i < 3; $i++) {
        postJson('/webhooks/stripe', ['nothing' => 'useful'])->assertStatus(400);
    }

    expect(GatewayWebhookEvent::query()->where('signature_valid', false)->count())->toBe(3);
})->group('fast');

it('keeps the payload out of anything serialised', function (): void {
    [$tenant] = WebhookScenario::make();

    postJson('/webhooks/stripe', $p = WebhookScenario::stripeSuccess('evt_hidden'), WebhookScenario::signedStripeHeaders($p))->assertOk();

    $event = GatewayWebhookEvent::query()->where('event_id', 'evt_hidden')->firstOrFail();

    // A gateway payload carries a cardholder name and the last four digits.
    // `$hidden` keeps it out of the log context, the queue payload and the
    // Sentry breadcrumb — the same posture as `payments.raw_payload` and
    // `integration_credentials.credentials` (SEC-9, MYD-15).
    expect($event->toArray())->not->toHaveKey('payload')
        ->and((string) json_encode($event))->not->toContain(WebhookScenario::REFERENCE);
})->group('fast');
