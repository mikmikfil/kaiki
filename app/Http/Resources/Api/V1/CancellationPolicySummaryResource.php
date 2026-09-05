<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\CancellationPolicy;
use App\Models\CancellationPolicyTier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The guest-facing cancellation terms (`docs/api.md`,
 * `CancellationPolicySummary`; spec CXL-1).
 *
 * ## `captured_at` is null here, always
 *
 * The contract lists it as *"present only on a booking's snapshot"*. This is the
 * **live catalogue policy**, which is a different thing to what a booking is
 * refunded against: CXL-1 requires a refund to be computed from the policy
 * frozen at booking time, so editing a policy today must not change what a
 * booking made last week is owed. Null is the field saying which of the two
 * this is — a client that renders a capture date on a live policy would be
 * telling the guest something untrue.
 *
 * Tiers arrive sorted `days_before` descending from the relation, which is both
 * evaluation order (§3.3) and the order a refund ladder reads in.
 *
 * @mixin CancellationPolicy
 */
final class CancellationPolicySummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'summary' => $this->summary,
            'free_cancellation_hours' => $this->free_cancellation_hours,
            'tiers' => $this->tiers
                ->map(static fn (CancellationPolicyTier $tier): array => [
                    'days_before' => $tier->days_before,
                    'refund_percent' => $tier->refund_percent,
                ])
                ->values()
                ->all(),
            'weather_refund_percent' => $this->weather_refund_percent,
            'force_majeure_voucher_months' => $this->force_majeure_voucher_months,
            'no_show_refund_percent' => $this->no_show_refund_percent,
            'captured_at' => null,
        ];
    }
}
