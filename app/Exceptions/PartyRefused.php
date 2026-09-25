<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Domain\Availability\Support\PartyGuard;
use App\Domain\Booking\Actions\CreateBookingDraft;
use App\Enums\AvailabilityRejection;
use App\Models\Product;
use RuntimeException;

/**
 * This party may not be written as a booking (AVL-25, AVL-26, CAT-5; 2026-09-25).
 *
 * Thrown by {@see CreateBookingDraft} on {@see PartyGuard::admit()}'s answer,
 * before a row or a hold exists. It carries the same machine code the calendar
 * and `POST /price-quote` report, so the widget, the API and the panel read one
 * refusal in the same words, in Greek and English, from the lang files (CNV-11).
 */
final class PartyRefused extends RuntimeException
{
    private function __construct(
        public readonly AvailabilityRejection $rejection,
        private readonly ?Product $product,
    ) {
        parent::__construct($rejection->sentenceIn(app()->getLocale(), $product));
    }

    /** @throws self when there is a refusal */
    public static function throwIf(?AvailabilityRejection $rejection, Product $product): void
    {
        if ($rejection !== null) {
            throw new self($rejection, $product);
        }
    }

    /** The sentence in a given language, for the API's `message` / `message_el`. */
    public function messageIn(string $locale): string
    {
        return $this->rejection->sentenceIn($locale, $this->product);
    }
}
