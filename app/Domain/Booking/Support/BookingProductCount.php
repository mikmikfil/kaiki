<?php

declare(strict_types=1);

namespace App\Domain\Booking\Support;

use App\Domain\Catalog\Contracts\ProductBookingCount;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Product;

/**
 * How many commitments a product already carries (`docs/data-model.md` §2.3).
 *
 * The fourth M1 seam, filled here for the same reason as the three in
 * {@see BookingHoldSource}: `SaveProduct` refuses a `mode` change once a
 * booking exists, and until `bookings` existed the guard correctly refused
 * nothing. #18's `AppServiceProvider` note says so in as many words — *"M2 adds
 * one implementation of ProductBookingCount and one entry here"*.
 *
 * ## What counts as a commitment
 *
 * Anything that is not dead. A `draft` counts even before it is paid: the
 * booking snapshotted `products.mode` at creation, and switching the product
 * from `per_seat` to `per_vessel` underneath a guest who is at the checkout
 * right now reinterprets what they are buying. `expired` and `cancelled` do not
 * count — nobody is holding anything and the operator should not be locked out
 * of their own catalogue by a booking somebody abandoned in April.
 *
 * Soft-deleted bookings are excluded by the model's own scope, which is
 * correct: a deleted booking is one an operator removed on purpose.
 */
final class BookingProductCount implements ProductBookingCount
{
    public function countFor(Product $product): int
    {
        return Booking::query()
            ->where('product_id', $product->getKey())
            ->whereNotIn('status', [
                BookingStatus::Expired->value,
                BookingStatus::Cancelled->value,
            ])
            ->count();
    }
}
