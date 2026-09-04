<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\BookingMode;
use App\Models\Product;
use RuntimeException;

/**
 * A product's `mode` was changed after bookings existed.
 *
 * `docs/data-model.md` §2.3: *"a `per_seat` product that becomes `per_vessel`
 * would invalidate every departure and every price snapshot's meaning."* Every
 * departure generated for it counts seats that the new mode does not have, and
 * every frozen price was computed under rules that no longer apply. There is no
 * safe migration between the two — the honest answer is a new product.
 *
 * Thrown from the Action rather than a form rule, because the API and the
 * importer are equally capable of asking, and #16's split applies: the panel
 * will show the same sentence beside the field once the resource exists (#24).
 *
 * The message is assembled from a lang file (CNV-11) rather than written here.
 */
final class ProductModeLocked extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly BookingMode $requested,
        public readonly int $bookingCount,
    ) {
        parent::__construct($message);
    }

    public static function forProduct(Product $product, BookingMode $requested, int $bookingCount): self
    {
        return new self(self::message($product, $requested, $bookingCount), $requested, $bookingCount);
    }

    /**
     * Public and static so the Filament resource can show exactly this sentence
     * as a field error without an exception to carry it. Two builders producing
     * two wordings for one refusal is how an operator learns to distrust both.
     */
    public static function message(Product $product, BookingMode $requested, int $bookingCount): string
    {
        return (string) trans('catalog.product.validation.mode_locked', [
            'title' => $product->title,
            'from' => $product->mode->label(),
            'to' => $requested->label(),
            'count' => $bookingCount,
        ]);
    }
}
