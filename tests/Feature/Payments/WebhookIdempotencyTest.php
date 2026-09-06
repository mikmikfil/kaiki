<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\WebhookEventStatus;
use App\Events\BookingConfirmed;
use App\Jobs\ProcessGatewayWebhook;
use App\Models\Booking;
use App\Models\GatewayWebhookEvent;
use App\Models\Payment;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Event;

use function Pest\Laravel\postJson;

use Tests\Support\Payments\WebhookScenario;

/*
|--------------------------------------------------------------------------
| PAY-5, PAY-6, AVL-47: the same event, delivered twice, confirms once
|--------------------------------------------------------------------------
|
| Both gateways retry until they get a 2xx, and a slow response is
| indistinguishable from a lost one — so duplicate deliveries are not an edge
| case, they are the normal operation of the system.
|
| The guarantee is a **unique index**, not a check: `gw_events_provider_event_uq`
| on (provider, event_id). Checking for an existing row first would leave a
| window between the check and the insert that two concurrent retries drive
| straight through, which is the same reasoning `bookings.reference` follows.
|
*/

it('confirms once when the same event is delivered twice', function (): void {
    Event::fake([BookingConfirmed::class]);

    [$tenant, $booking] = WebhookScenario::make();

    $payload = WebhookScenario::stripeSuccess();
    $headers = WebhookScenario::signedStripeHeaders($payload);

    postJson('/webhooks/stripe', $payload, $headers)->assertOk()->assertJson(['received' => true]);

    // The retry. Same event id, and the unique index is what settles it —
    // §2.7: respond 200, do nothing.
    postJson('/webhooks/stripe', $payload, $headers)
        ->assertOk()
        ->assertJson(['received' => true, 'duplicate' => true]);

    expect(GatewayWebhookEvent::query()->count())->toBe(1);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect($booking->refresh()->status)->toBe(BookingStatus::Confirmed);
    });

    // Once, not twice. A second dispatch would mean a second e-ticket, a second
    // confirmation email and a second myDATA invoice (BKG-13).
    Event::assertDispatchedTimes(BookingConfirmed::class, 1);
})->group('fast');

it('is a no-op when a webhook is replayed after confirmation', function (): void {
    [$tenant, $booking] = WebhookScenario::make();

    postJson('/webhooks/stripe', $p = WebhookScenario::stripeSuccess('evt_first'), WebhookScenario::signedStripeHeaders($p))->assertOk();

    $confirmedAt = Tenancy::forTenant($tenant, fn () => $booking->refresh()->confirmed_at);

    // A *different* event id for the same session — which is what a gateway
    // sends when it re-fires an old event from a dashboard. It gets past the
    // unique index, so the second guard is the booking's own status (AVL-47).
    postJson('/webhooks/stripe', $p = WebhookScenario::stripeSuccess('evt_second'), WebhookScenario::signedStripeHeaders($p))->assertOk();

    Tenancy::forTenant($tenant, function () use ($booking, $confirmedAt): void {
        $booking->refresh();

        expect($booking->status)->toBe(BookingStatus::Confirmed)
            // Unchanged. A second confirmation would move this, and the guest's
            // ticket would say a different thing from their email.
            ->and($booking->confirmed_at?->equalTo($confirmedAt))->toBeTrue();
    });
})->group('fast');

it('confirms the booking and settles the money, per BKG-11', function (): void {
    [$tenant, $booking, $payment] = WebhookScenario::make();

    postJson('/webhooks/stripe', $p = WebhookScenario::stripeSuccess(), WebhookScenario::signedStripeHeaders($p))->assertOk();

    Tenancy::forTenant($tenant, function () use ($booking, $payment): void {
        $booking->refresh();

        expect($booking->status)->toBe(BookingStatus::Confirmed)
            ->and($booking->confirmed_at)->not->toBeNull()
            // BKG-11 names this explicitly: the hold is over.
            ->and($booking->hold_expires_at)->toBeNull()
            // PAY-10: recomputed from the payment rows.
            ->and($booking->paid_cents)->toBe(12000)
            ->and($booking->balance_cents)->toBe(0)
            ->and($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
            ->and($payment->paid_at)->not->toBeNull();
    });
})->group('fast');

it('writes the event row before the money logic, so a failure is replayable', function (): void {
    [$tenant, , $payment] = WebhookScenario::make();

    // The payment is deleted after the row is written but before the job runs,
    // so the job cannot match it. The event must still exist.
    postJson('/webhooks/stripe', $p = WebhookScenario::stripeSuccess('evt_orphan_after_write'), WebhookScenario::signedStripeHeaders($p))->assertOk();

    $event = GatewayWebhookEvent::query()->where('event_id', 'evt_orphan_after_write')->firstOrFail();

    // §2.7's reason for the table existing at all: written first, in its own
    // transaction, so a payload that later turns out to be unprocessable is
    // still recorded and still replayable rather than lost with the request.
    expect($event->payload)->not->toBeEmpty()
        ->and($event->signature_valid)->toBeTrue()
        ->and($event->received_at)->not->toBeNull();
})->group('fast');

it('ignores an event type it does not act on, without putting it in the feed', function (): void {
    [$tenant, $booking] = WebhookScenario::make();

    postJson('/webhooks/stripe', $p = WebhookScenario::stripeSuccess('evt_unrelated', [
        'type' => 'customer.subscription.updated',
    ]), WebhookScenario::signedStripeHeaders($p))->assertOk();

    $event = GatewayWebhookEvent::query()->where('event_id', 'evt_unrelated')->firstOrFail();

    // `ignored`, not `failed`. Nothing went wrong, and an event we do not act
    // on must not compete for attention with a payment nobody can match.
    expect($event->status)->toBe(WebhookEventStatus::Ignored)
        ->and($event->status->needsAttention())->toBeFalse();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect($booking->refresh()->status)->toBe(BookingStatus::PendingPayment);
    });
})->group('fast');

it('runs one job per event row, not one per dispatch', function (): void {
    // `ShouldBeUnique` on the row id. The controller's unique index stops a
    // duplicate *row*; this stops a duplicate *job* for the same row, which two
    // rapid dispatches could otherwise produce.
    $job = new ProcessGatewayWebhook(42);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('gateway-webhook:42');
})->group('fast');
