<?php

declare(strict_types=1);

namespace App\Domain\Booking\Support;

use App\Data\Pricing\CancellationPolicyData;
use App\Domain\Pricing\Support\RefundCalculator;

/**
 * A booking's cancellation policy, spelled out line by line for the guest
 * (product owner, 2026-09-17).
 *
 * The checkout already showed the policy's one-sentence summary. What a guest
 * about to pay wants is the whole ladder: how much comes back fifteen days
 * out, how much the day before, what happens when the operator cancels for the
 * weather. This reads the **frozen** `policy_snapshot` — the same copy
 * {@see RefundCalculator} computes a refund from — so the page cannot promise
 * one thing and the refund do another.
 *
 * Every line follows the calculator's own rules: free cancellation first, then
 * each tier from the widest, and «no refund» below the narrowest tier, because
 * CXL-3.2 says a cancellation no tier covers refunds nothing.
 */
final class PolicyExplanation
{
    /**
     * @param  array<string, mixed>|null  $snapshot  `bookings.policy_snapshot`
     * @return list<string>
     */
    public static function lines(?array $snapshot, string $locale): array
    {
        if ($snapshot === null || $snapshot === []) {
            return [];
        }

        $policy = CancellationPolicyData::fromSnapshot($snapshot);
        $lines = [];

        if ($policy->freeCancellationHours !== null) {
            $lines[] = __('guest.policy.free_hours', ['hours' => $policy->freeCancellationHours], $locale);
        }

        foreach ($policy->tiers as $tier) {
            $lines[] = __('guest.policy.tier', [
                'days' => $tier->daysBefore,
                'percent' => $tier->refundPercent,
            ], $locale);
        }

        $narrowest = $policy->tiers === [] ? null : $policy->tiers[array_key_last($policy->tiers)];

        if ($narrowest !== null && $narrowest->daysBefore > 0) {
            $lines[] = __('guest.policy.later', ['days' => $narrowest->daysBefore], $locale);
        } elseif ($narrowest === null && $policy->freeCancellationHours === null) {
            $lines[] = __('guest.policy.no_refund', [], $locale);
        }

        $lines[] = __('guest.policy.weather', ['percent' => $policy->weatherRefundPercent], $locale);

        if ($policy->forceMajeureVoucherMonths > 0) {
            $lines[] = __('guest.policy.voucher', ['months' => $policy->forceMajeureVoucherMonths], $locale);
        }

        $lines[] = __('guest.policy.no_show', ['percent' => $policy->noShowRefundPercent], $locale);

        return $lines;
    }
}
