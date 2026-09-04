<?php

declare(strict_types=1);

namespace App\Data\Pricing;

use App\Domain\Pricing\Support\RefundCalculator;
use App\Models\CancellationPolicy;
use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;

/**
 * A cancellation policy, frozen (`docs/data-model.md` §3.3, spec CXL-1, CXL-2).
 *
 * This is the shape that lands in `bookings.policy_snapshot` in M2, and it is
 * **the only thing {@see RefundCalculator} will
 * accept**. That constraint is the entire point of the issue.
 *
 * ## Why the calculator must not be handed a model
 *
 * CXL-1 is fixed: *"Editing a `CancellationPolicy` MUST NOT affect any existing
 * booking."* A calculator that took a model — or worse, an id it could load —
 * would satisfy every test written today and silently break that invariant the
 * first time an operator edited a policy with bookings already on the books.
 * The failure would be money: a guest cancelling under the terms they agreed to
 * in June would be refunded under the terms the operator wrote in August, and
 * nothing would report it.
 *
 * So the refund path takes a value object, and the only way to obtain one is
 * {@see self::fromModel()} at booking time or {@see self::fromSnapshot()} from
 * the stored JSON afterwards. There is no third route.
 *
 * ## The tiers arrive sorted
 *
 * §3.3 says the snapshot's tiers are ordered by `days_before` **descending**,
 * and evaluation takes the first that qualifies. Sorting here rather than in
 * the calculator means the stored JSON is already in evaluation order — so a
 * snapshot read years later cannot depend on the sort a future version of the
 * calculator happens to apply.
 */
final class CancellationPolicyData extends Data
{
    /** Bumped on any shape change, so an old snapshot is always readable (§3.3). */
    public const VERSION = 1;

    /**
     * @param  list<CancellationTierData>  $tiers  sorted by `days_before` descending
     * @param  array<string, string>  $name  translatable, el/en
     * @param  array<string, string>|null  $summary
     */
    public function __construct(
        public readonly ?int $policyId,
        public readonly array $name,
        public readonly ?array $summary,
        public readonly ?int $freeCancellationHours,
        public readonly int $weatherRefundPercent,
        public readonly int $forceMajeureVoucherMonths,
        public readonly int $noShowRefundPercent,
        public readonly array $tiers,
        public readonly Carbon $capturedAt,
        public readonly int $version = self::VERSION,
    ) {}

    /** Freeze a live policy. The one place a model becomes a snapshot. */
    public static function fromModel(CancellationPolicy $policy): self
    {
        $tiers = $policy->tiers
            ->sortByDesc('days_before')
            ->map(fn ($tier): CancellationTierData => new CancellationTierData(
                daysBefore: (int) $tier->days_before,
                refundPercent: (int) $tier->refund_percent,
            ))
            ->values()
            ->all();

        return new self(
            policyId: $policy->getKey(),
            name: $policy->getTranslations('name'),
            summary: $policy->getTranslations('summary') ?: null,
            freeCancellationHours: $policy->free_cancellation_hours,
            weatherRefundPercent: $policy->weather_refund_percent,
            forceMajeureVoucherMonths: $policy->force_majeure_voucher_months,
            noShowRefundPercent: $policy->no_show_refund_percent,
            tiers: $tiers,
            capturedAt: Carbon::now('UTC'),
        );
    }

    /**
     * Read a stored snapshot back.
     *
     * Tolerant of a missing `tiers` key and of tiers arriving unsorted, because
     * this reads rows written by earlier versions of this application and a
     * refund must not fail on a shape detail. It is **not** tolerant of a
     * missing percentage: those default to the same values the column defaults
     * use, so a snapshot written before a field existed refunds the way the
     * policy would have.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function fromSnapshot(array $snapshot): self
    {
        /** @var array<int, array<string, mixed>> $rawTiers */
        $rawTiers = is_array($snapshot['tiers'] ?? null) ? $snapshot['tiers'] : [];

        $tiers = array_map(
            static fn (array $tier): CancellationTierData => new CancellationTierData(
                daysBefore: (int) ($tier['days_before'] ?? 0),
                refundPercent: (int) ($tier['refund_percent'] ?? 0),
            ),
            array_values($rawTiers),
        );

        usort($tiers, static fn (CancellationTierData $a, CancellationTierData $b): int => $b->daysBefore <=> $a->daysBefore);

        return new self(
            policyId: isset($snapshot['policy_id']) ? (int) $snapshot['policy_id'] : null,
            name: is_array($snapshot['name'] ?? null) ? $snapshot['name'] : [],
            summary: is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : null,
            freeCancellationHours: isset($snapshot['free_cancellation_hours'])
                ? (int) $snapshot['free_cancellation_hours']
                : null,
            weatherRefundPercent: (int) ($snapshot['weather_refund_percent'] ?? 100),
            forceMajeureVoucherMonths: (int) ($snapshot['force_majeure_voucher_months'] ?? 18),
            noShowRefundPercent: (int) ($snapshot['no_show_refund_percent'] ?? 0),
            tiers: $tiers,
            capturedAt: Carbon::parse((string) ($snapshot['captured_at'] ?? 'now'))->utc(),
            version: (int) ($snapshot['version'] ?? self::VERSION),
        );
    }

    /**
     * The §3.3 JSON, exactly.
     *
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return [
            'version' => $this->version,
            'policy_id' => $this->policyId,
            'name' => $this->name,
            'summary' => $this->summary,
            'free_cancellation_hours' => $this->freeCancellationHours,
            'weather_refund_percent' => $this->weatherRefundPercent,
            'force_majeure_voucher_months' => $this->forceMajeureVoucherMonths,
            'no_show_refund_percent' => $this->noShowRefundPercent,
            'tiers' => array_map(
                static fn (CancellationTierData $tier): array => [
                    'days_before' => $tier->daysBefore,
                    'refund_percent' => $tier->refundPercent,
                ],
                $this->tiers,
            ),
            'captured_at' => $this->capturedAt->toIso8601ZuluString(),
        ];
    }
}
