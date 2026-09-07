<?php

declare(strict_types=1);

namespace App\Data\Catalog;

use App\Models\Departure;
use App\Models\Product;
use Spatie\LaravelData\Data;

/**
 * One trip that can take the searched party on the searched date.
 *
 * The two fields a from-price grid cannot give — what **this** party pays, and
 * when the next sailing leaves — are the reason the whole feature exists. A grid
 * that says "από 65 €" and charges 162,50 € at checkout is the search experience
 * guests telephone to avoid.
 *
 * `partyPriceCents` is null only for an on-request product (BKG-24): a `quote`
 * trip never shows a price, and a null here is a statement rather than a gap.
 */
final class SearchResultData extends Data
{
    public const AVAILABLE = 'available';

    /** A `quote` product: listed, priceless, and answered by the operator. */
    public const ON_REQUEST = 'on_request';

    public function __construct(
        public readonly Product $product,
        public readonly string $availability,
        public readonly ?int $partyPriceCents,
        public readonly ?Departure $nextDeparture,
    ) {}

    public function isOnRequest(): bool
    {
        return $this->availability === self::ON_REQUEST;
    }
}
