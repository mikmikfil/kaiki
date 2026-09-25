<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Models\Booking;
use App\Models\Departure;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A deleted trip, gone for good (Mike, 25/9: «στις διαγραμμένες εκδρομές να έχει
 * κάδο, και από εκεί να πηγαίνει στα τελείως deleted»).
 *
 * ## Only a trip nobody ever booked
 *
 * A booking points at its trip and must keep doing so for as long as the
 * booking exists: the invoice, the ticket, the passenger list and the refund
 * all read the trip it was for, and `bookings.product_id` refuses the delete
 * for exactly that reason. So a trip with any booking — cancelled and test
 * ones included — stays in «Διαγραμμένες», where it is out of sale and out of
 * sight, and this says why instead of failing on the constraint.
 *
 * ## The order
 *
 * Its departures first (they refuse the trip's delete, and nothing booked sits
 * on them), then the trip; age bands, price lists, schedule rules, extras,
 * questions and FAQs go with it by their own `cascadeOnDelete`. One
 * transaction, so a refusal half-way leaves everything as it was.
 */
final class ForceDeleteProduct
{
    public function __invoke(Product $product): void
    {
        if (Booking::query()->withTrashed()->where('product_id', $product->getKey())->exists()) {
            throw ValidationException::withMessages([
                'product' => __('catalog.product.force_delete.has_bookings'),
            ]);
        }

        try {
            DB::transaction(static function () use ($product): void {
                Departure::query()->where('product_id', $product->getKey())->delete();

                $product->forceDelete();
            });
        } catch (QueryException) {
            // Something else still points at it (a row this class does not know
            // about yet). Refused, and nothing was changed.
            throw ValidationException::withMessages([
                'product' => __('catalog.product.force_delete.in_use'),
            ]);
        }
    }
}
