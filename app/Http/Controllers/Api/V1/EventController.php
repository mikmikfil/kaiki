<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Analytics\Actions\CountAnalyticsEvent;
use App\Domain\Analytics\Support\AnalyticsMetric;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The widget's analytics beacon (ADR-0032).
 *
 * ## It answers 204 whatever happens
 *
 * This is the one endpoint in the API that must never be able to affect a
 * booking. It is called with `navigator.sendBeacon` from a page that is in the
 * middle of selling something, and a 400 here — or worse, a slow response —
 * would be a defect in the widget's error handling away from a guest seeing a
 * failure. So an unknown event name, a malformed body and a missing tenant all
 * produce the same empty success, and the count is simply not made.
 *
 * That is not laxity: what protects the table is the **allow-list**, not the
 * status code. {@see AnalyticsMetric::fromWidgetEvent()} resolves the eight
 * names WGT-11 fixed and nothing else, and the only other thing read out of the
 * body is a product uuid, matched against the shape of a uuid.
 *
 * ## Nothing here identifies anybody
 *
 * No IP is stored, no user agent, no cookie is set and no identifier is
 * returned. The row that comes out of this is *"this tenant, this day, this
 * metric, one more"*. GDR-12 holds, and there is nothing to ask consent for —
 * which is the whole reason the product counts its own visits instead of
 * pasting in somebody else's script.
 */
class EventController
{
    public function __construct(private readonly CountAnalyticsEvent $count) {}

    public function __invoke(Request $request): JsonResponse
    {
        $tenant = Tenancy::current();

        if (! $tenant instanceof Tenant) {
            return $this->accepted();
        }

        foreach ($this->events($request) as $event) {
            $this->record($tenant, $event);
        }

        return $this->accepted();
    }

    /**
     * The events in the body, however the caller shaped it.
     *
     * One event or a small batch: `sendBeacon` cannot be retried and cannot
     * read a response, so a widget that has queued two events while the page
     * was hidden sends them together. Capped, because a batch is a convenience
     * and not an upload.
     *
     * @return list<array<string, mixed>>
     */
    private function events(Request $request): array
    {
        $body = $request->json()->all();
        $events = is_array($body['events'] ?? null) ? $body['events'] : [$body];
        $clean = [];

        foreach (array_slice($events, 0, 20) as $event) {
            if (is_array($event)) {
                $clean[] = $event;
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function record(Tenant $tenant, array $event): void
    {
        $metric = AnalyticsMetric::fromWidgetEvent((string) ($event['event'] ?? ''));

        if ($metric === null) {
            return;
        }

        // The only dimension a browser may set. A product uuid is a public
        // identifier that the widget was already given, matched here against
        // the shape of one so the column cannot be filled with prose.
        $product = (string) ($event['product_uuid'] ?? '');
        $isUuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $product) === 1;

        ($this->count)(
            $tenant,
            $metric,
            $isUuid ? 'product' : '',
            $isUuid ? strtolower($product) : '',
            // Only where the metric carries money, and only as an integer of
            // cents — a float here would be a price computed in a browser,
            // which is the one thing the widget is never allowed to send.
            $metric->carriesValue() && is_int($event['value_cents'] ?? null)
                ? max(0, (int) $event['value_cents'])
                : 0,
        );
    }

    private function accepted(): JsonResponse
    {
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
