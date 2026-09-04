<?php

declare(strict_types=1);

namespace App\Data\Pricing;

use App\Support\Money\Cents;
use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;

/**
 * The full server-side derivation (`docs/data-model.md` §3.4, spec PRC-14).
 *
 * Brief §5.7: the widget never sends prices, and this object must be enough to
 * **explain the total to a guest a year later without touching any other
 * table**. Everything here is therefore a copy, not a reference: the season's
 * name, each line's label, the VAT rate that applied. A snapshot that pointed
 * at rows would answer next year's question with next year's data.
 *
 * ## The invariant, and where it is enforced
 *
 * §3.4: `subtotal_cents + extras_cents − discount_cents = total_cents`. It
 * holds by construction here — `total_cents` is computed from the other three
 * rather than passed in — so a caller cannot assemble a snapshot that does not
 * add up.
 *
 * ## `version` exists so this shape can change
 *
 * A snapshot read years from now was written by the code of its own year. The
 * version is what lets a future reader know which rules produced it, and it is
 * bumped on any change to the keys below.
 */
final class PriceSnapshotData extends Data
{
    public const VERSION = 1;

    /**
     * @param  list<PriceLineData>  $lines
     * @param  array<string, mixed>|null  $season  id, name, priority — §3.4
     * @param  array<string, mixed>|null  $vat  null while a product has no VAT rate (M1)
     * @param  array<string, mixed>  $deposit  type, percent, amount_cents
     */
    public function __construct(
        public readonly string $source,
        public readonly Carbon $computedAt,
        public readonly ?int $ratePlanId,
        public readonly ?array $season,
        public readonly string $mode,
        public readonly array $lines,
        public readonly int $subtotalCents,
        public readonly int $extrasCents,
        public readonly int $discountCents,
        public readonly int $totalCents,
        public readonly ?array $vat,
        public readonly array $deposit,
        public readonly ?int $cancellationPolicyId = null,
    ) {}

    /** @return array<string, mixed> the §3.4 shape, exactly */
    public function toArray(): array
    {
        $snapshot = [
            'version' => self::VERSION,
            'source' => $this->source,
            'currency' => Cents::CURRENCY,
            // UTC and ISO-8601 with a `Z`, as §3.4 shows it. A local time here
            // would be unreadable to the myDATA client and ambiguous across a
            // DST change.
            'computed_at' => $this->computedAt->utc()->toIso8601ZuluString(),
            'rate_plan_id' => $this->ratePlanId,
            'season' => $this->season,
            'mode' => $this->mode,
            'lines' => array_map(
                static fn (PriceLineData $line): array => $line->toSnapshot(),
                $this->lines,
            ),
            'subtotal_cents' => $this->subtotalCents,
            'extras_cents' => $this->extrasCents,
            'discount_cents' => $this->discountCents,
            'total_cents' => $this->totalCents,
            'vat' => $this->vat,
            'deposit' => $this->deposit,
            'rounding' => Cents::ROUNDING,
        ];

        // Provenance for a refund years later: which policy the guest agreed
        // to. Not in §3.4's example, which shows a booking whose policy lives
        // in `policy_snapshot`; kept optional so a quote can carry it.
        if ($this->cancellationPolicyId !== null) {
            $snapshot['cancellation_policy_id'] = $this->cancellationPolicyId;
        }

        return $snapshot;
    }

    /** §3.4's invariant, asserted rather than assumed. */
    public function addsUp(): bool
    {
        return $this->subtotalCents + $this->extrasCents - $this->discountCents === $this->totalCents;
    }
}
