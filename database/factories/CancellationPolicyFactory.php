<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CancellationPolicy;
use App\Models\CancellationPolicyTier;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CancellationPolicy> */
class CancellationPolicyFactory extends Factory
{
    protected $model = CancellationPolicy::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => ['el' => 'Ευέλικτη', 'en' => 'Flexible'],
            'summary' => [
                'el' => 'Δωρεάν ακύρωση έως 48 ώρες πριν την αναχώρηση.',
                'en' => 'Free cancellation up to 48 hours before departure.',
            ],
            'free_cancellation_hours' => 48,
            'weather_refund_percent' => 100,
            'force_majeure_voucher_months' => 18,
            'no_show_refund_percent' => 0,
            'is_default' => false,
        ];
    }

    public function default(): self
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }

    /** A policy with no free-cancellation window, so the ladder always decides. */
    public function withoutFreeCancellation(): self
    {
        return $this->state(fn (): array => ['free_cancellation_hours' => null]);
    }

    /**
     * The §3.3 example ladder: 15 days → 100%, 7 → 50%, 2 → 0%.
     *
     * Pinned rather than random, because every refund assertion in the suite is
     * about which rung a date lands on.
     *
     * @param  array<int, int>  $ladder  days_before => refund_percent
     */
    public function withTiers(array $ladder = [15 => 100, 7 => 50, 2 => 0]): self
    {
        return $this->afterCreating(function (CancellationPolicy $policy) use ($ladder): void {
            foreach ($ladder as $daysBefore => $refundPercent) {
                CancellationPolicyTier::query()->create([
                    'cancellation_policy_id' => $policy->getKey(),
                    'days_before' => $daysBefore,
                    'refund_percent' => $refundPercent,
                ]);
            }
        });
    }
}
