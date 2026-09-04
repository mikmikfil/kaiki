<?php

declare(strict_types=1);

namespace Tests\Support\Catalog;

use App\Domain\Catalog\Contracts\ProductBookingCount;
use App\Models\Product;

/**
 * A booking count, without a `bookings` table.
 *
 * `SaveProduct`'s mode guard ships before the thing it guards against exists
 * (M2), so the only way to prove the refusal today is to hand it a source that
 * says a product has commitments. Same role as {@see FakeCapacityClaims} plays
 * for #16's capacity guard.
 *
 * When M2 registers a real implementation, this stays: it is still the cheapest
 * way to test the refusal without constructing a booking.
 */
final class FakeProductBookingCount implements ProductBookingCount
{
    public function __construct(private readonly int $count) {}

    public function countFor(Product $product): int
    {
        return $this->count;
    }
}
