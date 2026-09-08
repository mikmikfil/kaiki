<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

use App\Enums\DeliveryStatus;
use App\Enums\ExportStatus;
use App\Enums\FailureSource;
use App\Enums\PaymentStatus;
use App\Filament\App\Resources\NotificationLogResource;
use App\Models\ExportJob;
use App\Models\GatewayWebhookEvent;
use App\Models\IcalSource;
use App\Models\NotificationLog;
use App\Models\Payment;
use App\Models\WebhookDelivery;
use App\Support\Tenancy;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Everything that failed, in one list (spec OPS-21, NFR-8).
 *
 * ## It is a view over six tables, and deliberately not a seventh
 *
 * The tempting build is an `operator_failures` table that everything writes
 * into. It would make this class a single query and would be wrong: each source
 * already records its own failure, with its own retry semantics and its own
 * idea of what "resolved" means, and a copy of that in a seventh table drifts
 * the first time one is updated and the other is not. An operator would then
 * have two records of the same event disagreeing about whether it is still a
 * problem.
 *
 * So each source stays the authority and this merges. The cost is six queries
 * and a sort in PHP; the bound is {@see self::PER_SOURCE} and {@see self::DAYS},
 * which keeps it to a page of rows rather than a season of them.
 *
 * ## What this is *not*, and the boundary is already written down
 *
 * {@see AttentionItems} is a **decision** list: a departure below its minimum,
 * a hold about to expire, a quote nobody answered. Nothing there failed —
 * somebody has to choose.
 *
 * This is the other half: things the system **tried and could not do**, each
 * with a retry where a retry is meaningful. The two are one click apart and
 * must not become one screen, because "we could not send an email" and "this
 * boat is half empty tomorrow" want completely different reactions.
 *
 * The one deliberate overlap is a broken calendar. It appears in both — as a
 * decision on the dashboard, because a boat that looks free while somebody else
 * sold it is the most expensive silence in the product, and as a failure here,
 * because that is where the operator finds out *why*.
 */
final class FailureFeed
{
    /**
     * How far back the feed looks.
     *
     * Thirty days. A failure older than that has either been dealt with or has
     * stopped mattering, and a feed that goes back further becomes a list
     * nobody scrolls to the bottom of — which is the same as a feed nobody
     * reads.
     */
    public const DAYS = 30;

    /**
     * The cap per source, before merging.
     *
     * Per source rather than overall, so one noisy table cannot crowd the other
     * five off the screen. A hundred failed webhooks must not hide the one
     * refused payment.
     */
    public const PER_SOURCE = 50;

    /**
     * @return list<FailureItem>
     */
    public function all(?Carbon $now = null): array
    {
        if (! Tenancy::check()) {
            return [];
        }

        $since = ($now ?? Carbon::now())->copy()->subDays(self::DAYS);

        $items = [
            ...$this->notifications($since),
            ...$this->payments($since),
            ...$this->gatewayWebhooks($since),
            ...$this->calendars(),
            ...$this->exports($since),
            ...$this->outboundWebhooks($since),
        ];

        usort(
            $items,
            static fn (FailureItem $a, FailureItem $b): int => $b->failedAtSortKey() <=> $a->failedAtSortKey(),
        );

        return $items;
    }

    /** @return list<FailureItem> */
    private function notifications(Carbon $since): array
    {
        return NotificationLog::query()
            ->needingAttention()
            ->where('created_at', '>=', $since)
            ->with('booking')
            ->latest('created_at')
            ->limit(self::PER_SOURCE)
            ->get()
            ->map(fn (NotificationLog $log): FailureItem => new FailureItem(
                source: FailureSource::Notification,
                id: $log->getKey(),
                key: 'notification:' . $log->getKey(),
                failedAt: $this->at($log->created_at),
                title: (string) __('failures.notification.title', [
                    'channel' => $log->channel->label(),
                    'to' => $this->mask((string) $log->to),
                ]),
                // The one place in the product that already turns a provider
                // code into a sentence. Reused rather than re-implemented.
                explanation: NotificationLogResource::explain($log->error_message),
                detail: $log->error_message,
                bookingReference: $log->booking?->reference,
                retryable: FailureSource::Notification->isRetryable(),
            ))
            ->all();
    }

    /** @return list<FailureItem> */
    private function payments(Carbon $since): array
    {
        return Payment::query()
            ->where('status', PaymentStatus::Failed)
            ->where('created_at', '>=', $since)
            ->with('booking')
            ->latest('created_at')
            ->limit(self::PER_SOURCE)
            ->get()
            ->map(fn (Payment $payment): FailureItem => new FailureItem(
                source: FailureSource::Payment,
                id: $payment->getKey(),
                key: 'payment:' . $payment->getKey(),
                failedAt: $this->at($payment->created_at),
                title: (string) __('failures.payment.title', [
                    'gateway' => (string) $payment->gateway?->label(),
                ]),
                explanation: (string) __('failures.payment.explanation'),
                detail: $payment->gateway_ref,
                bookingReference: $payment->booking?->reference,
                // A bank refused a card. The retry is the guest's, on their own
                // card, and a button here would promise the operator something
                // they cannot do on somebody else's behalf.
                retryable: FailureSource::Payment->isRetryable(),
            ))
            ->all();
    }

    /** @return list<FailureItem> */
    private function gatewayWebhooks(Carbon $since): array
    {
        return GatewayWebhookEvent::query()
            ->needingAttention()
            ->where('received_at', '>=', $since)
            ->latest('received_at')
            ->limit(self::PER_SOURCE)
            ->get()
            ->map(fn (GatewayWebhookEvent $event): FailureItem => new FailureItem(
                source: FailureSource::GatewayWebhook,
                id: $event->getKey(),
                key: 'gateway_webhook:' . $event->getKey(),
                failedAt: $this->at($event->received_at),
                title: (string) __('failures.gateway_webhook.title', [
                    'provider' => $event->provider->label(),
                ]),
                explanation: (string) __('failures.gateway_webhook.' . $event->status->value),
                detail: $event->error_message ?? $event->event_type,
                bookingReference: null,
                retryable: FailureSource::GatewayWebhook->isRetryable(),
            ))
            ->all();
    }

    /**
     * Calendars we can no longer read.
     *
     * No date filter, unlike everything else. A broken calendar is a **state**
     * rather than an event — it has been failing every fifteen minutes since
     * whenever it broke — and one that has been dead for six weeks is more
     * urgent than one that broke this morning, not less. Filtering it out at
     * thirty days would hide exactly the worst case.
     *
     * @return list<FailureItem>
     */
    private function calendars(): array
    {
        return IcalSource::query()
            ->with('vessel')
            ->where('consecutive_failures', '>=', IcalSource::ATTENTION_THRESHOLD)
            ->orderByDesc('consecutive_failures')
            ->limit(self::PER_SOURCE)
            ->get()
            ->map(fn (IcalSource $source): FailureItem => new FailureItem(
                source: FailureSource::IcalSync,
                id: $source->getKey(),
                key: 'ical:' . $source->getKey(),
                // The last time it was tried, which is what an operator wants
                // to know: a calendar last attempted an hour ago is still being
                // polled, and one from last week means the sweep stopped too.
                failedAt: $this->at($source->last_synced_at ?? $source->updated_at),
                title: (string) __('failures.ical.title', [
                    'name' => $source->name,
                    'vessel' => (string) $source->vessel?->name,
                ]),
                explanation: (string) __('failures.ical.explanation', [
                    'count' => $source->consecutive_failures,
                ]),
                detail: $source->last_error,
                bookingReference: null,
                retryable: FailureSource::IcalSync->isRetryable(),
            ))
            ->all();
    }

    /** @return list<FailureItem> */
    private function exports(Carbon $since): array
    {
        return ExportJob::query()
            ->where('status', ExportStatus::Failed)
            ->where('created_at', '>=', $since)
            ->latest('created_at')
            ->limit(self::PER_SOURCE)
            ->get()
            ->map(fn (ExportJob $job): FailureItem => new FailureItem(
                source: FailureSource::Export,
                id: $job->getKey(),
                key: 'export:' . $job->getKey(),
                failedAt: $this->at($job->created_at),
                title: (string) __('failures.export.title', ['type' => $job->type->label()]),
                explanation: (string) __('failures.export.explanation'),
                detail: $job->error,
                bookingReference: null,
                retryable: FailureSource::Export->isRetryable(),
            ))
            ->all();
    }

    /** @return list<FailureItem> */
    private function outboundWebhooks(Carbon $since): array
    {
        return WebhookDelivery::query()
            ->where('status', DeliveryStatus::Failed)
            ->where('created_at', '>=', $since)
            ->with('endpoint')
            ->latest('created_at')
            ->limit(self::PER_SOURCE)
            ->get()
            ->map(fn (WebhookDelivery $delivery): FailureItem => new FailureItem(
                source: FailureSource::OutboundWebhook,
                id: $delivery->getKey(),
                key: 'webhook_delivery:' . $delivery->getKey(),
                failedAt: $this->at($delivery->created_at),
                title: (string) __('failures.outbound_webhook.title', [
                    'endpoint' => (string) ($delivery->endpoint->name ?? '—'),
                    'event' => $delivery->event->label(),
                ]),
                explanation: (string) __('failures.outbound_webhook.explanation', [
                    'status' => $delivery->response_status ?? __('failures.outbound_webhook.no_answer'),
                ]),
                detail: $delivery->response_body,
                bookingReference: null,
                retryable: FailureSource::OutboundWebhook->isRetryable(),
            ))
            ->all();
    }

    /**
     * A timestamp that is always present in practice and nullable in the types.
     *
     * `created_at` is nullable on every Eloquent model — a row built in memory
     * and never saved has none — and every row here came out of the database,
     * so it does. Falling back to *now* rather than dropping the row: a failure
     * with an unreadable date is still a failure an operator has to see, and it
     * sorts to the top where they will notice it rather than vanishing.
     *
     * `DateTimeInterface` rather than `Carbon`, because the casts hand back
     * `Carbon\Carbon` and this file speaks `Illuminate\Support\Carbon` — the
     * two are not the same class and only one of them has `diffForHumans` in
     * the application's own locale.
     */
    private function at(?DateTimeInterface $value): Carbon
    {
        return $value === null ? Carbon::now() : Carbon::instance($value);
    }

    /**
     * A recipient, shortened rather than shown whole.
     *
     * The feed is a list of failures, not a directory. `maria@example.gr`
     * becomes `mar…@example.gr` — enough for an operator to recognise which
     * guest it was, and not a screen somebody can photograph to collect
     * addresses. The whole value is on the notification log itself, which is
     * behind its own permission.
     */
    private function mask(string $recipient): string
    {
        if (! str_contains($recipient, '@')) {
            // A telephone number. The last four is what somebody recognises.
            return Str::length($recipient) > 4
                ? '…' . Str::substr($recipient, -4)
                : $recipient;
        }

        [$local, $domain] = explode('@', $recipient, 2);

        return Str::limit($local, 3, '…') . '@' . $domain;
    }
}
