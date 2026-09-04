<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Data;

use App\Domain\Catalog\Contracts\VesselCapacityClaims;
use Spatie\LaravelData\Data;

/**
 * One record standing in the way of lowering a vessel's `capacity_max`.
 *
 * A flat, already-rendered description rather than the offending model itself,
 * because the sources are heterogeneous — a product and a departure share no
 * base class — and the only consumer is a sentence an operator reads. Handing
 * back models would make the message builder switch on class, which is the
 * thing this DTO exists to avoid.
 *
 * `label` is built by the source, in the operator's locale, because only the
 * source knows how to name its own row: "Sunset cruise" for a product, "Sat 14
 * Jun, 10:00" for a departure.
 *
 * @see VesselCapacityClaims
 */
final class CapacityClaim extends Data
{
    public function __construct(
        /** What kind of record this is — `product`, `departure`. Used for grouping and icons. */
        public readonly string $kind,
        /** How the operator would recognise it, already localised. */
        public readonly string $label,
        /** The pax count that exceeds the proposed capacity. */
        public readonly int $pax,
        /** The public identifier, so the message can link to the record. Null for rows with no uuid. */
        public readonly ?string $uuid = null,
    ) {}

    /** "Sunset cruise (12)" — one offender, as it appears in the refusal message. */
    public function toLine(): string
    {
        return "{$this->label} ({$this->pax})";
    }
}
