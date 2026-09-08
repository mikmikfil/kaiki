<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Webhooks\Support\SafeUrl;
use App\Domain\Webhooks\Support\WebhookSignature;
use App\Enums\DeliveryStatus;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * One POST, and the decision about whether there will be another
 * (spec OPS-20, `docs/api.md` §8.4).
 *
 * ## The retry schedule is ours, not the queue's
 *
 * Laravel's own `$tries` and `backoff()` would work, and are wrong here for two
 * reasons. The schedule is **published** — an integrator reads §8.4's eight
 * intervals and sizes their retention against them — so it has to live
 * somewhere a person can read, which is `WebhookDelivery::BACKOFF_SECONDS`. And
 * the panel has to *show* where a delivery has got to: which attempt, what the
 * receiver said, when the next one is due. A queue's internal retry state
 * answers none of that.
 *
 * So the job never throws to signal a retry. It records the attempt, computes
 * `next_attempt_at`, and re-dispatches itself with a delay. A worker restart
 * loses nothing, because the row is the source of truth and the sweeper picks
 * up anything whose delay was lost with the job.
 *
 * ## The SSRF check runs again, here, immediately before the call
 *
 * It ran at save time too. That is not redundancy: DNS can change between the
 * two, and a hostname that resolved to a public address when the operator saved
 * it can resolve to `169.254.169.254` an hour later. The check that matters is
 * the one closest to the socket.
 *
 * ## A refused URL is abandoned, not retried
 *
 * Retrying an SSRF attempt eight times over a day is eight attempts at the same
 * refusal. It is marked `failed` once with a plain reason, so the operator sees
 * it in OPS-21's feed and fixes the URL.
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    /**
     * One attempt per job.
     *
     * The retrying is done by re-dispatch, not by the queue, so a job that
     * throws has genuinely failed rather than merely not delivered — and there
     * is nothing useful a second immediate run would do differently.
     */
    public int $tries = 1;

    public function __construct(private readonly int $deliveryId) {}

    public function handle(HttpFactory $http): void
    {
        $delivery = WebhookDelivery::query()->withoutGlobalScopes()->find($this->deliveryId);

        if (! $delivery instanceof WebhookDelivery || ! $delivery->status->isPending()) {
            // Already delivered, already given up on, or pruned. A duplicate
            // job is a no-op rather than a second POST.
            return;
        }

        $tenant = Tenancy::withoutTenancy(
            static fn (): ?Tenant => Tenant::query()->find($delivery->tenant_id),
        );

        if (! $tenant instanceof Tenant) {
            $delivery->forceFill(['status' => DeliveryStatus::Abandoned])->save();

            return;
        }

        Tenancy::forTenant($tenant, function () use ($delivery, $http): void {
            $endpoint = $delivery->endpoint;

            if (! $endpoint instanceof WebhookEndpoint || ! $endpoint->isDeliverable()) {
                // Switched off or deleted while this was queued. Nothing
                // failed; there is nowhere to send it. See `DeliveryStatus`.
                $delivery->forceFill(['status' => DeliveryStatus::Abandoned])->save();

                return;
            }

            $this->attempt($delivery, $endpoint, $http);
        });
    }

    private function attempt(WebhookDelivery $delivery, WebhookEndpoint $endpoint, HttpFactory $http): void
    {
        $now = Carbon::now();
        $attempt = $delivery->attempts + 1;

        $body = (string) json_encode($delivery->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = $now->getTimestamp();

        if (! SafeUrl::isAllowed($endpoint->url)) {
            // See the class docblock: this is a refusal, not an outage.
            $this->giveUp($delivery, $endpoint, $attempt, null, 'refused: the URL resolves somewhere a webhook may not go');

            return;
        }

        $started = microtime(true);

        try {
            $response = $http
                ->timeout(WebhookDelivery::TIMEOUT_SECONDS)
                ->withoutRedirecting()
                ->withHeaders([
                    'Content-Type' => 'application/json; charset=utf-8',
                    'User-Agent' => 'Kaiki-Webhooks/1',
                    'Kaiki-Event' => $delivery->event->value,
                    'Kaiki-Delivery-Id' => $delivery->event_id,
                    'Kaiki-Timestamp' => (string) $timestamp,
                    'Kaiki-Signature' => WebhookSignature::header(
                        [$endpoint->signing_secret],
                        $timestamp,
                        $body,
                    ),
                    'Kaiki-Attempt' => (string) $attempt,
                ])
                ->withBody($body, 'application/json')
                ->post($endpoint->url);
        } catch (Throwable $e) {
            $this->recordFailedAttempt($delivery, $endpoint, $attempt, null, Str::limit($e->getMessage(), 500), $this->elapsed($started));

            return;
        }

        $duration = $this->elapsed($started);

        if ($response->successful()) {
            $delivery->forceFill([
                'status' => DeliveryStatus::Delivered,
                'attempts' => $attempt,
                'next_attempt_at' => null,
                'response_status' => $response->status(),
                'response_body' => Str::limit($response->body(), WebhookDelivery::BODY_EXCERPT),
                'duration_ms' => $duration,
                'delivered_at' => Carbon::now(),
            ])->save();

            $endpoint->recordSuccess();

            return;
        }

        $this->recordFailedAttempt(
            $delivery,
            $endpoint,
            $attempt,
            $response->status(),
            Str::limit($response->body(), WebhookDelivery::BODY_EXCERPT),
            $duration,
        );
    }

    /** One failed attempt: schedule the next, or give up if that was the last. */
    private function recordFailedAttempt(
        WebhookDelivery $delivery,
        WebhookEndpoint $endpoint,
        int $attempt,
        ?int $status,
        ?string $body,
        int $duration,
    ): void {
        $delivery->forceFill([
            'attempts' => $attempt,
            'response_status' => $status,
            'response_body' => $body,
            'duration_ms' => $duration,
        ]);

        if (! $delivery->hasAttemptsLeft()) {
            $this->giveUp($delivery, $endpoint, $attempt, $status, $body);

            return;
        }

        $next = $delivery->nextAttemptAt();

        $delivery->forceFill(['next_attempt_at' => $next])->save();

        self::dispatch($delivery->getKey())->delay($next);
    }

    /** The attempts ran out, or the URL was refused. */
    private function giveUp(
        WebhookDelivery $delivery,
        WebhookEndpoint $endpoint,
        int $attempt,
        ?int $status,
        ?string $body,
    ): void {
        $delivery->forceFill([
            'status' => DeliveryStatus::Failed,
            'attempts' => $attempt,
            'next_attempt_at' => null,
            'response_status' => $status,
            'response_body' => $body,
        ])->save();

        if ($endpoint->recordFailure()) {
            Log::warning('webhooks.endpoint_disabled', [
                'endpoint' => $endpoint->uuid,
                'tenant' => $endpoint->tenant_id,
                'consecutive_failures' => $endpoint->consecutive_failures,
            ]);
        }
    }

    private function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
