<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of `GET /api/v1/sync/products` (`docs/api.md`, `ProductSyncItem`;
 * spec WPP-6).
 *
 * @mixin Product
 */
final class ProductSyncResource extends JsonResource
{
    /**
     * The translatable fields the mirror renders, and only those.
     *
     * `itinerary_stops` is absent on purpose: its coordinates live in a `_geo`
     * pseudo-locale that {@see Product::itineraryTranslations()} exists to strip,
     * and a stop list is structure rather than prose — the resolved `product`
     * below carries it already joined to its coordinates, which is the only
     * shape anything can render.
     *
     * @var list<string>
     */
    private const TRANSLATABLE = [
        'title', 'summary', 'description',
        'includes', 'excludes', 'what_to_bring',
        'meta_title', 'meta_description',
    ];

    /**
     * @param  list<string>  $locales  The tenant's, so a mirror is not handed a
     *                                 locale the operator does not publish.
     */
    public function __construct(Product $product, private readonly array $locales)
    {
        parent::__construct($product);
    }

    /**
     * ## A tombstone is short, and that is the contract
     *
     * §5 says a deleted row carries "only the fields needed to unpublish the CPT
     * entry". Sending the rest would be worse than wasteful: a mirror that reads
     * a title off a tombstone has a reason to keep rendering the page, and the
     * whole point of the row is that it must stop.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->resource;

        $base = [
            'uuid' => $product->uuid,
            'slug' => $product->slug,
            'status' => $product->status->value,
            'tombstone' => $product->trashed(),
            'deleted_at' => $product->deleted_at?->toIso8601ZuluString(),
            'updated_at' => $product->updated_at?->toIso8601ZuluString(),
        ];

        if ($product->trashed()) {
            return $base + ['content_hash' => null];
        }

        $translations = $this->translations();

        return $base + [
            'mode' => $product->mode->value,
            'category' => $product->category->value,
            'content_hash' => self::hash($translations),
            'translations' => $translations,
            // Resolved to the tenant's default locale, for the facts that have
            // no language at all — duration, capacity, images, the boat, the
            // cancellation tiers. A mirror needs both the prose *and* these, and
            // `translations` carries only the prose.
            'product' => (new ProductDetailResource($product))->resolve($request),
        ];
    }

    /**
     * The unresolved `{"el": …, "en": …}` shape, which crosses the API boundary
     * **here and nowhere else** (§3.1).
     *
     * The reason is WPML and Polylang: the plugin creates one post per language
     * and links them as translations of each other, so it needs both at once.
     * Every other endpoint resolves to the negotiated locale, and a test asserts
     * that this is the only path where a raw translation map appears.
     *
     * A locale the operator supports but has not filled in appears as an empty
     * value rather than being omitted. Omitting it would be indistinguishable
     * from "this field does not exist", and the mirror would keep whatever it
     * wrote last time — so a translation an operator *cleared* would never
     * clear downstream.
     *
     * @return array<string, array<string, mixed>>
     */
    private function translations(): array
    {
        $out = [];

        foreach ($this->locales as $locale) {
            $fields = [];

            foreach (self::TRANSLATABLE as $field) {
                $fields[$field] = $this->resource->getTranslations($field)[$locale] ?? null;
            }

            $out[$locale] = $fields;
        }

        return $out;
    }

    /**
     * A stable fingerprint of the prose, so a mirror can skip the write.
     *
     * Stable means order-independent: `json_encode` of a PHP array follows
     * insertion order, and the field order here is fixed by `TRANSLATABLE` while
     * the locale order comes from the tenant's own list. Sorting both means the
     * hash answers "did the content change" rather than "did anything about how
     * we assembled it change" — a mirror that rewrites every post because a
     * locale moved in a config array is a mirror that touches every `post_date`
     * and reshuffles the operator's sitemap.
     *
     * @param  array<string, array<string, mixed>>  $translations
     */
    private static function hash(array $translations): string
    {
        ksort($translations);

        foreach ($translations as $locale => $fields) {
            ksort($fields);
            $translations[$locale] = $fields;
        }

        return substr(hash('sha256', (string) json_encode($translations)), 0, 16);
    }
}
