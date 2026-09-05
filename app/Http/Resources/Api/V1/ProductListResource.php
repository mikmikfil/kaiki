<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Media\Support\ImagePayload;
use App\Enums\BookingMode;
use App\Models\Product;
use App\Support\Format\MoneyFormatter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A product as the `list` mount, `[kaiki_list]` and the hosted landing page
 * read it (`docs/api.md`, `ProductSummary`; spec WGT-5, WGT-13, HOS-1).
 *
 * ## No database id reaches this payload, by construction
 *
 * CNV-8 and SEC-2. Every identifier here is a uuid, including the nested
 * vessel's and meeting point's — `ProductPayloadTest` walks the whole response
 * recursively looking for an `id` key or an integer that matches a primary key,
 * because an audit that only checks the top level passes the day someone adds a
 * relation.
 *
 * ## The price is read, never computed
 *
 * PRC-1 and WGT-13: *"the widget never computes a price"*, and neither does
 * this endpoint. `products.price_from_cents` is derived and written by
 * `RecomputePriceFrom` (#33); reading the column is the whole of the work here.
 *
 * PRC-5 is why null is emitted rather than zero when there is no resolvable
 * rate plan — **a zero price on a boat trip is a support incident, not a UI
 * quirk**. A `mode: quote` product is null for a different reason: it never
 * shows a price at all, so the null is a statement rather than a gap.
 *
 * `from_price_formatted` is a convenience and says so in the contract. The
 * cents are the source of truth; the string is what a client renders when it
 * has no money formatter of its own, which is every WordPress theme.
 */
class ProductListResource extends JsonResource
{
    /** @var Product */
    public $resource;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $product = $this->resource;
        $locale = app()->getLocale();
        $fromPrice = $this->fromPriceCents();

        return [
            'uuid' => $product->uuid,
            'slug' => $product->slug,
            'title' => $product->title,
            'summary' => $product->summary,
            'category' => $product->category->value,
            'mode' => $product->mode->value,
            'duration_minutes' => $product->duration_minutes,
            'default_start_time' => self::wallTime($product->default_start_time),
            'flexible_start' => $product->flexible_start,
            'from_price_cents' => $fromPrice,
            'from_price_formatted' => $fromPrice !== null
                ? MoneyFormatter::format($fromPrice, $locale, MoneyFormatter::currency())
                : null,
            'currency' => MoneyFormatter::currency(),
            'hero_image_url' => $this->heroImageUrl($locale),
            'vessel' => $product->vessel !== null
                ? new VesselSummaryResource($product->vessel)
                : null,
            'meeting_point' => $product->meetingPoint !== null
                ? new MeetingPointResource($product->meetingPoint)
                : null,
            'min_booking_pax' => $product->min_booking_pax,
            'max_pax' => $product->max_pax,
            'is_featured' => $product->is_featured,
            'sort_order' => $product->sort_order,
            // Hosted pages arrive in M3 (HOS-1). Null rather than absent, for
            // the reason `custom_css` is null on the branding payload: a client
            // that branches on presence breaks the day the field starts
            // carrying a value.
            'booking_url' => null,
            'updated_at' => $product->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The derived "from" price, or null.
     *
     * A `quote` product is null unconditionally — BKG-24 and the contract both
     * say it never shows a price, and a stale `price_from_cents` left behind by
     * a mode change must not leak one.
     */
    protected function fromPriceCents(): ?int
    {
        return $this->resource->mode === BookingMode::Quote
            ? null
            : $this->resource->price_from_cents;
    }

    /** The first gallery image, which is what a card renders. */
    private function heroImageUrl(string $locale): ?string
    {
        return ImagePayload::collection($this->resource->images, $locale)[0]['url'] ?? null;
    }

    /**
     * A `time` column as `HH:MM`.
     *
     * The driver hands back `09:00:00` and the contract's pattern allows five
     * characters. Trimmed rather than reformatted through a date object: this
     * is tenant-local **wall time**, not an instant, and putting it through a
     * timezone-aware type is how it acquires one.
     */
    protected static function wallTime(?string $time): ?string
    {
        return $time !== null ? substr($time, 0, 5) : null;
    }
}
