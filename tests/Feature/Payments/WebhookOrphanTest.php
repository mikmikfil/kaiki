<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\WebhookEventStatus;
use App\Models\Booking;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Departure;
use App\Models\GatewayWebhookEvent;
use App\Models\Payment;
use App\Support\Tenancy;

use function Pest\Laravel\postJson;

use Tests\Support\Payments\WebhookScenario;

/*
|--------------------------------------------------------------------------
| PAY-7 and BKG-12: the two outcomes that are not a confirmation
|--------------------------------------------------------------------------
|
| PAY-7: *"a verified webhook for an unknown booking is stored and surfaced in
| the super-admin gateway error feed rather than discarded."*
|
| An orphan is a real payment. The signature was good, the money moved, and we
| cannot say what it was for — somebody has been charged. That needs a person,
| not a retry and not a discard, and it needs to be visibly different from the
| event types we simply do not act on.
|
| BKG-12 is the other non-confirmation: the payment failed, the seats go back,
| and the guest gets a fresh hold if the boat still has room.
|
*/

it('stores a verified webhook for an unknown payment and surfaces it', function (): void {
    WebhookScenario::make();

    $payload = WebhookScenario::stripeSuccess('evt_orphan', [
        'data' => ['object' => ['id' => 'cs_a_session_we_never_created']],
    ]);

    // 2xx, because the gateway did nothing wrong and retrying would not help.
    postJson('/webhooks/stripe', $payload, WebhookScenario::signedStripeHeaders($payload))->assertOk();

    $event = GatewayWebhookEvent::query()->where('event_id', 'evt_orphan')->firstOrFail();

    // `orphaned`, not `failed` and not `ignored`. `failed` would say something
    // went wrong when nothing did; `ignored` would say we looked and decided it
    // did not concern us, and would hide it from the feed it belongs in.
    expect($event->status)->toBe(WebhookEventStatus::Orphaned)
        ->and($event->status->needsAttention())->toBeTrue()
        // Never resolved to a tenant, because it never matched a payment.
        ->and($event->tenant_id)->toBeNull()
        ->and($event->payment_id)->toBeNull()
        // The payload survives, which is what makes it investigable at all.
        ->and($event->payload)->not->toBeEmpty();

    // And it appears in the platform feed PAY-7 names.
    expect(GatewayWebhookEvent::query()->needingAttention()->count())->toBe(1);
})->group('fast');

it('does not put an ignored event type in the feed', function (): void {
    WebhookScenario::make();

    $payload = WebhookScenario::stripeSuccess('evt_noise', ['type' => 'invoice.paid']);

    postJson('/webhooks/stripe', $payload, WebhookScenario::signedStripeHeaders($payload))->assertOk();

    // The distinction the fifth status exists for: an event we do not act on
    // must not compete for attention with a payment nobody can match.
    expect(GatewayWebhookEvent::query()->needingAttention()->count())->toBe(0);
})->group('fast');

it('backfills the tenant onto the event once the payment is matched', function (): void {
    [$tenant] = WebhookScenario::make();

    $payload = WebhookScenario::stripeSuccess('evt_matched');

    postJson('/webhooks/stripe', $payload, WebhookScenario::signedStripeHeaders($payload))->assertOk();

    $event = GatewayWebhookEvent::query()->where('event_id', 'evt_matched')->firstOrFail();

    // §2.7: *"backfilled once the payment is matched"*. The whole reason
    // `tenant_id` is nullable, and the reason this model is on the
    // platform-owned list rather than carrying `BelongsToTenant` — a global
    // scope would hide the row from the job whose task is to work out whose it
    // is.
    expect($event->tenant_id)->toBe($tenant->getKey())
        ->and($event->payment_id)->not->toBeNull()
        ->and($event->status)->toBe(WebhookEventStatus::Processed);
})->group('fast');

it('gives the seats back and re-holds them when a payment fails', function (): void {
    [$tenant, $booking] = WebhookScenario::make(capacity: 10);

    $payload = WebhookScenario::stripeSuccess('evt_failed', [
        'type' => 'payment_intent.payment_failed',
    ]);

    postJson('/webhooks/stripe', $payload, WebhookScenario::signedStripeHeaders($payload))->assertOk();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $booking->refresh();

        // BKG-12's happy branch: back to `draft` with a fresh hold, because the
        // guest is still there and the boat still has room.
        expect($booking->status)->toBe(BookingStatus::Draft)
            ->and($booking->holdsSeats())->toBeTrue();

        $departure = Departure::query()->findOrFail($booking->departure_id);

        // Out of `seats_sold` — they went in at redirect (BKG-9) and nobody
        // paid for them — and back into `seats_held`.
        expect($departure->seats_sold)->toBe(0)
            ->and($departure->seats_held)->toBe(2);

        expect(Payment::query()->firstOrFail()->status)->toBe(PaymentStatus::Failed);
    });
})->group('fast');

it('expires the booking when the boat filled while the guest was failing to pay', function (): void {
    [$tenant, $booking] = WebhookScenario::make(capacity: 2);

    // **Another guest holding the seats**, not merely a counter set by hand.
    // `HoldSeats` recounts live holds from `bookings` rather than trusting
    // `departures.seats_held`, so a bare counter would be corrected away and
    // the re-hold would succeed — the test would pass the wrong branch.
    //
    // This is also what actually happens: the window in which this booking's
    // seats are briefly free is the window somebody else takes them in.
    Tenancy::forTenant($tenant, function () use ($booking): void {
        Booking::factory()
            ->forDeparture(Departure::query()->findOrFail($booking->departure_id))
            ->withPax(2, 2)
            ->holding(Departure::query()->findOrFail($booking->departure_id))
            ->create();

        Departure::query()->whereKey($booking->departure_id)->update(['seats_held' => 2]);
    });

    $payload = WebhookScenario::stripeSuccess('evt_failed_full', [
        'type' => 'payment_intent.payment_failed',
    ]);

    postJson('/webhooks/stripe', $payload, WebhookScenario::signedStripeHeaders($payload))->assertOk();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $booking->refresh();

        // Expired, with a reason a guest-facing page can turn into a sentence
        // rather than a shrug.
        expect($booking->status)->toBe(BookingStatus::Expired)
            ->and($booking->cancel_reason?->value)->toBe('payment_failed')
            ->and($booking->hold_expires_at)->toBeNull();
    });
})->group('fast');

it('does not undo a confirmation with a late failure webhook', function (): void {
    [$tenant, $booking] = WebhookScenario::make();

    $success = WebhookScenario::stripeSuccess('evt_ok');
    postJson('/webhooks/stripe', $success, WebhookScenario::signedStripeHeaders($success))->assertOk();

    // A failure event arriving after the success — out of order delivery, which
    // both gateways can do.
    $failure = WebhookScenario::stripeSuccess('evt_late_failure', [
        'type' => 'payment_intent.payment_failed',
    ]);
    postJson('/webhooks/stripe', $failure, WebhookScenario::signedStripeHeaders($failure))->assertOk();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        // Still confirmed. The guard is the booking's status, not the order the
        // events arrived in — which is the only thing that can be relied on.
        expect($booking->refresh()->status)->toBe(BookingStatus::Confirmed);
    });
})->group('fast');

it('is not tenant-owned, and the isolation gate knows why', function (): void {
    // The third case that list has met. `Tenant` and `User` are the platform;
    // `VatRate` is Greek tax law. This one is written before the tenant is
    // known and *acquires* one later, which is why it is listed rather than
    // given `BelongsToTenant` — a global scope would hide the row from the job
    // whose whole task is to work out whose it is.
    expect(config('tenancy.platform_owned_models'))->toContain(GatewayWebhookEvent::class);

    $traits = class_uses_recursive(GatewayWebhookEvent::class);

    expect($traits)->not->toContain(BelongsToTenant::class);
})->group('fast');
