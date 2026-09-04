<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Domain\Catalog\Data\CapacityClaim;
use App\Observers\VesselObserver;
use App\Rules\VesselCapacityNotLowered;
use RuntimeException;

/**
 * A vessel's `capacity_max` was lowered below a promise already made.
 *
 * Thrown from {@see VesselObserver}, the same way
 * {@see MissingTranslationException} is thrown from the search-index observer,
 * and for the same reason: a form is not the only writer. An import or an API
 * call that quietly shrank a boat would leave sold departures over capacity,
 * and the manifest is where somebody would eventually notice — on the quay.
 *
 * Expected to be **unreachable through the panel**, where
 * {@see VesselCapacityNotLowered} shows the operator the same list beside the
 * field instead. Both delegate to the same Action, so they cannot disagree
 * about what counts as an offender.
 *
 * The message is deliberately assembled from a lang file rather than written
 * here: CNV-11 requires that an error reaching an operator exists in Greek and
 * English and comes from a lang file, "never from an exception message".
 */
final class CapacityLoweringRefused extends RuntimeException
{
    /**
     * @param  list<CapacityClaim>  $claims
     */
    private function __construct(
        string $message,
        public readonly int $requestedCapacity,
        public readonly array $claims,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  list<CapacityClaim>  $claims
     */
    public static function forClaims(int $requestedCapacity, array $claims): self
    {
        return new self(self::message($requestedCapacity, $claims), $requestedCapacity, $claims);
    }

    /**
     * The operator-facing sentence, listing what is in the way.
     *
     * Public and static because {@see VesselCapacityNotLowered} needs exactly
     * this string without an exception to carry it — the form shows it as a
     * field error, not as a failure. Two builders producing two wordings for
     * the same refusal is how an operator learns to distrust the message.
     *
     * @param  list<CapacityClaim>  $claims
     */
    public static function message(int $requestedCapacity, array $claims): string
    {
        return (string) trans('catalog.vessel.capacity.refused', [
            'capacity' => $requestedCapacity,
            'count' => count($claims),
            'records' => implode(', ', array_map(
                static fn (CapacityClaim $claim): string => $claim->toLine(),
                $claims,
            )),
        ]);
    }
}
