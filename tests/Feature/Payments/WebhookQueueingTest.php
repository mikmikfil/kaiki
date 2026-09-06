<?php

declare(strict_types=1);

use App\Enums\WebhookEventStatus;
use App\Jobs\ProcessGatewayWebhook;
use App\Models\GatewayWebhookEvent;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\postJson;

use Tests\Support\Payments\WebhookScenario;

/*
|--------------------------------------------------------------------------
| PAY-6: the work is queued, and the request does not wait for it
|--------------------------------------------------------------------------
|
| The other half of the pair. `WebhookIdempotencyTest` and `WebhookOrphanTest`
| set the queue driver to `sync` so they can assert what processing *does*; this
| one fakes the queue so it can assert that the request **queues** rather than
| doing it inline.
|
| Split rather than combined, for the reason #53 wrote down: a test that runs
| the job inline and calls that "queued" is a test that passes on a sync driver
| and proves nothing about the real one. Both halves have to be asserted
| separately, or neither is.
|
| PAY-6's five seconds is the requirement behind it. Both gateways retry
| anything slower, so a request that did the money logic inline would generate
| the duplicate deliveries the whole table exists to absorb.
|
*/

it('queues the processing rather than doing it in the request', function (): void {
    Queue::fake();

    WebhookScenario::make();

    $payload = WebhookScenario::stripeSuccess('evt_queued');

    postJson('/webhooks/stripe', $payload, WebhookScenario::signedStripeHeaders($payload))
        ->assertOk()
        ->assertJson(['received' => true]);

    $event = GatewayWebhookEvent::query()->where('event_id', 'evt_queued')->firstOrFail();

    // The row exists and the answer is already sent — before any money logic
    // has run. That ordering is what keeps the response inside PAY-6's budget.
    expect($event->status)->toBe(WebhookEventStatus::Received)
        ->and($event->processed_at)->toBeNull();

    Queue::assertPushed(
        ProcessGatewayWebhook::class,
        fn (ProcessGatewayWebhook $job): bool => $job->eventId === $event->getKey(),
    );
})->group('fast');

it('queues nothing for a duplicate delivery', function (): void {
    Queue::fake();

    WebhookScenario::make();

    $payload = WebhookScenario::stripeSuccess('evt_dup');
    $headers = WebhookScenario::signedStripeHeaders($payload);

    postJson('/webhooks/stripe', $payload, $headers)->assertOk();
    postJson('/webhooks/stripe', $payload, $headers)->assertOk()->assertJson(['duplicate' => true]);

    // One job, not two. The unique index refuses the second row, and no row
    // means nothing to dispatch — the deduplication happens before the queue
    // rather than inside it.
    Queue::assertPushed(ProcessGatewayWebhook::class, 1);
})->group('fast');

it('queues nothing for an unverified request', function (): void {
    Queue::fake();

    WebhookScenario::make();

    postJson('/webhooks/stripe', WebhookScenario::stripeSuccess('evt_forged'))->assertStatus(400);

    // Recorded for PAY-7's audit, and nothing more. A forged payload must not
    // be able to make the platform do work, which is half of what a rate limit
    // on this endpoint is protecting.
    Queue::assertNothingPushed();

    expect(GatewayWebhookEvent::query()->count())->toBe(1);
})->group('fast');
