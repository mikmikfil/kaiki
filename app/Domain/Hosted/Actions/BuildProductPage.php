<?php

declare(strict_types=1);

namespace App\Domain\Hosted\Actions;

use App\Enums\BookingMode;
use App\Models\Departure;
use App\Models\Faq;
use App\Models\Product;
use App\Support\Format\MoneyFormatter;
use Illuminate\Support\Collection;

/**
 * Everything one trip's hosted page renders, resolved before the template runs.
 *
 * The same rule as {@see BuildHomePage}: a template that queries is a template
 * that queries once per section, and this page has eight of them. What the view
 * receives is a product with its relations already loaded, the departures the
 * `Event` graph describes, the FAQ entries for this trip, and a price that has
 * already answered the one question the template must not be trusted with.
 *
 * ## The price is read, never computed
 *
 * PRC-1. `products.price_from_cents` is derived by #33 and this reads the
 * column, exactly as `GET /products` does. A `quote` product is **null
 * unconditionally** (BKG-24): it shows no price at all, and a stale
 * `price_from_cents` left behind by a mode change must not leak one — the same
 * guard `ProductListResource` applies, for the same reason, on the other side
 * of the same rule.
 *
 * ## Why the departures are capped
 *
 * A daily cruise scheduled a year out has 365 sellable departures, and every
 * one of them would become an `Event` node in the page's structured data.
 * That is a page several hundred kilobytes of JSON larger for a search engine
 * that samples the first few, on the hottest guest-facing read in the product.
 * The cap is {@see self::EVENT_HORIZON}, they are the *soonest* ones, and the
 * booking widget — not this page — is what answers "when else can I go".
 */
class BuildProductPage
{
    /**
     * How many upcoming departures reach the `Event` graph.
     *
     * Twenty is roughly three weeks of a daily cruise and every sailing of a
     * weekly one for five months, which is the horizon a guest is choosing
     * inside. See the class docblock for what the number is protecting.
     */
    public const EVENT_HORIZON = 20;

    public function __construct(private readonly BuildFaqList $faqs) {}

    /**
     * @return array{
     *     product: Product,
     *     departures: Collection<int, Departure>,
     *     faqs: Collection<int, Faq>,
     *     fromPriceCents: int|null,
     *     fromPriceFormatted: string|null,
     * }
     */
    public function __invoke(Product $product, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $fromPrice = $this->fromPriceCents($product);

        return [
            'product' => $product,
            'departures' => $this->departures($product),
            // This trip's own entries plus the operator's, its own first — the
            // rule lives in the Action, not here and not in the partial (#103).
            'faqs' => ($this->faqs)($product),
            'fromPriceCents' => $fromPrice,
            'fromPriceFormatted' => $fromPrice !== null
                ? MoneyFormatter::format($fromPrice, $locale, MoneyFormatter::currency())
                : null,
        ];
    }

    /**
     * The soonest sellable departures, from now.
     *
     * `sellable()` is the departure's own scope, so a cancelled sailing cannot
     * reach the page or its structured data — an `Event` for a trip that is not
     * running is worse than no `Event`, because a search engine will show it.
     *
     * Blocked departures are dropped for the same reason: `is_blocked` is the
     * cached answer to "the boat is unavailable that day", and a guest offered a
     * date the availability endpoint will refuse has been told something false.
     *
     * @return Collection<int, Departure>
     */
    public function departures(Product $product): Collection
    {
        return Departure::query()
            ->where('product_id', $product->getKey())
            ->sellable()
            ->where('is_blocked', false)
            ->where('starts_at_utc', '>=', now())
            ->orderBy('starts_at_utc')
            ->limit(self::EVENT_HORIZON)
            ->get();
    }

    /** BKG-24: a quote product has no price to show, whatever the column holds. */
    private function fromPriceCents(Product $product): ?int
    {
        return $product->mode === BookingMode::Quote ? null : $product->price_from_cents;
    }
}
