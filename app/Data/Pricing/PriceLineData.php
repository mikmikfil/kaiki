<?php

declare(strict_types=1);

namespace App\Data\Pricing;

use Spatie\LaravelData\Data;

/**
 * One line of a price snapshot (`docs/data-model.md` §3.4).
 *
 * The snapshot has to *"explain the total to a guest a year later without
 * touching any other table"*, which is why a line carries the **label at the
 * time** rather than a reference to fetch one. An operator renaming "Adult" to
 * "Ενήλικας 12+" next spring must not rewrite what a guest was shown last June.
 *
 * `total_cents` is stored rather than derived from `qty * unit_price_cents`,
 * for the same reason: it is the number that was actually charged, and a future
 * reader recomputing it would be re-deriving under whatever rules apply then.
 */
final class PriceLineData extends Data
{
    /**
     * @param  'pax'|'extra'|'fee'|'discount'  $kind
     * @param  array<string, string>  $label  translatable, el/en
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $ref,
        public readonly array $label,
        public readonly int $qty,
        public readonly int $unitPriceCents,
        public readonly int $totalCents,
        /** Present when the price was derived from the base band (§3.4). */
        public readonly ?int $multiplierBp = null,
        /** True for an `on_request` extra: recorded, never added (PRC-9). */
        public readonly bool $onRequest = false,
    ) {}

    /** @return array<string, mixed> the §3.4 shape, exactly */
    public function toSnapshot(): array
    {
        $line = [
            'kind' => $this->kind,
            'ref' => $this->ref,
            'label' => $this->label,
            'qty' => $this->qty,
            'unit_price_cents' => $this->unitPriceCents,
            'total_cents' => $this->totalCents,
        ];

        // Absent rather than null: §3.4 marks it "no" for required, and a null
        // in the JSON would read as "derived from nothing" rather than "not
        // derived".
        if ($this->multiplierBp !== null) {
            $line['multiplier_bp'] = $this->multiplierBp;
        }

        if ($this->onRequest) {
            $line['on_request'] = true;
        }

        return $line;
    }
}
