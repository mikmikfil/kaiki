<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Catalog\Data\OfferedExtra;
use App\Domain\Catalog\Support\OfferedExtrasResolver;
use App\Domain\Media\Support\ImagePayload;
use App\Models\Extra;
use App\Models\Product;
use App\Models\RatePlan;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The full product (`docs/api.md`, `Product`; spec HOS-1, WGT-5).
 *
 * `allOf` in the contract, `extends` here — the detail payload **is** the
 * summary plus more, and duplicating the twenty summary keys is how the two
 * drift apart the first time a field is renamed.
 *
 * ## Every translatable field is already one string
 *
 * §3.1: the raw `{"el": …, "en": …}` shape *"is an implementation detail and
 * never crosses the API boundary"*. The model accessors resolve it, so the only
 * field needing care is `itinerary_stops`, whose coordinates live in a `_geo`
 * sidecar outside the locales — see {@see Product::itineraryGeo()} for why they
 * are stored that way, and why `getTranslations()` cannot be walked naively.
 */
final class ProductDetailResource extends ProductListResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $product = $this->resource;
        $locale = app()->getLocale();
        $policy = $product->effectiveCancellationPolicy();

        return [
            ...parent::toArray($request),

            'description' => $product->description,
            // Null means "not configured" and hides the section; an empty array
            // means "configured as empty" (§3.5). The distinction is preserved
            // rather than normalised, because they are different statements to
            // a guest reading the page.
            'includes' => $product->includes,
            'excludes' => $product->excludes,
            'what_to_bring' => $product->what_to_bring,
            'itinerary_stops' => $this->itineraryStops($locale),
            'route_map_image_url' => ImagePayload::url($product->route_map_image_path),
            'images' => ImagePayload::collection($product->images, $locale),
            'age_bands' => AgeBandResource::collection($product->ageBands)->resolve($request),
            'extras' => $this->extras($request),
            'cancellation_policy' => $policy !== null
                ? (new CancellationPolicySummaryResource($policy))->resolve($request)
                : null,
            'check_in_offset_minutes' => $product->check_in_offset_minutes,
            'earliest_start_time' => self::wallTime($product->earliest_start_time),
            'latest_start_time' => self::wallTime($product->latest_start_time),
            'min_pax' => $product->min_pax,
            'guest_details_required' => $product->guest_details_required,
            'guest_details_deadline_hours' => $product->guest_details_deadline_hours,
            'booking_window' => $this->bookingWindow(),
            'seo' => $this->seo(),
            // The tenant's, not the server's. Every wall time above is local to
            // it, and a client that renders `09:00` without knowing where has no
            // way to turn it into an instant.
            'timezone' => Tenancy::current()?->timezone,
        ];
    }

    /**
     * The route, with the locale's labels and the sidecar's coordinates joined
     * on `key` (§3.6).
     *
     * The join is by key rather than by position on purpose: an operator who
     * adds a stop to the Greek list and not yet to the English one would
     * otherwise get every English coordinate shifted by one, and a map that is
     * subtly wrong is worse than a map with a missing pin.
     *
     * @return list<array<string, mixed>>
     */
    private function itineraryStops(string $locale): array
    {
        $geo = $this->resource->itineraryGeo();

        $stops = [];

        foreach ($this->resource->itineraryStopsFor($locale) as $stop) {
            if (! is_string($stop['key'] ?? null)) {
                continue;
            }

            $coordinates = $geo[$stop['key']] ?? [];

            $stops[] = [
                'key' => $stop['key'],
                'name' => is_string($stop['name'] ?? null) ? $stop['name'] : '',
                'description' => is_string($stop['description'] ?? null) ? $stop['description'] : null,
                'duration_minutes' => isset($stop['duration_minutes']) ? (int) $stop['duration_minutes'] : null,
                'lat' => isset($coordinates['lat']) ? (float) $coordinates['lat'] : null,
                'lng' => isset($coordinates['lng']) ? (float) $coordinates['lng'] : null,
            ];
        }

        return $stops;
    }

    /**
     * The extras this product offers, overrides applied.
     *
     * Not eager-loadable, and that is a property of the model rather than an
     * oversight: a tenant-wide extra reaches this product with no pivot row at
     * all, so membership is a union rather than a relation.
     * {@see OfferedExtrasResolver} answers it in two queries whatever the size
     * of the catalogue, and this endpoint resolves one product.
     *
     * @return list<array<string, mixed>>
     */
    private function extras(Request $request): array
    {
        $offered = OfferedExtrasResolver::forProduct($this->resource);

        /** @var Collection<int, Extra> $models */
        $models = Extra::query()
            ->whereIn('id', $offered->pluck('extraId')->all())
            ->get()
            ->keyBy('id');

        return $offered
            ->map(fn (OfferedExtra $extra): array => (new ExtraResource(
                $extra,
                $models->get($extra->extraId),
            ))->resolve($request))
            ->values()
            ->all();
    }

    /**
     * When this product may be booked at all (`docs/api.md`, `BookingWindow`).
     *
     * `min_lead_time_hours` and `max_advance_days` live on `rate_plans` and vary
     * by season, so a single product-level pair is necessarily a projection —
     * the contract says so, and records the rule it applies in the absence of an
     * ADR: **the strictest value across active plans**, so a client never offers
     * a date the booking endpoint will reject.
     *
     * Strictest means the *largest* lead time and the *smallest* advance window.
     * A null `max_advance_days` is "no limit" and is skipped rather than treated
     * as zero, which would close the calendar outright.
     *
     * The authoritative per-date answer is always `GET /availability`.
     *
     * @return array{min_lead_time_hours: int|null, max_advance_days: int|null}
     */
    private function bookingWindow(): array
    {
        $plans = $this->resource->ratePlans;

        $advance = $plans
            ->map(static fn (RatePlan $plan): ?int => $plan->max_advance_days)
            ->reject(static fn (?int $days): bool => $days === null);

        return [
            'min_lead_time_hours' => $plans->isEmpty()
                ? null
                : (int) $plans->max(static fn (RatePlan $plan): int => $plan->min_lead_time_hours),
            'max_advance_days' => $advance->isEmpty() ? null : (int) $advance->min(),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function seo(): array
    {
        return [
            'meta_title' => $this->resource->meta_title,
            'meta_description' => $this->resource->meta_description,
            'og_image_url' => ImagePayload::url($this->resource->og_image_path),
            // Both hosted-page fields land in M3 (HOS-2), with the JSON-LD that
            // consumes them. Null, never absent.
            'canonical_url' => null,
        ];
    }
}
