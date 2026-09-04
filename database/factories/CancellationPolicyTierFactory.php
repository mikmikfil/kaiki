<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CancellationPolicy;
use App\Models\CancellationPolicyTier;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CancellationPolicyTier> */
class CancellationPolicyTierFactory extends Factory
{
    protected $model = CancellationPolicyTier::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            // The isolation harness builds a tier with no policy in hand, so
            // one is created rather than required — inside the same tenant,
            // because `BelongsToTenant` assigns from the resolved context and a
            // tier pointing at another operator's policy is the leak #8 exists
            // to catch.
            'cancellation_policy_id' => CancellationPolicy::factory(),
            'days_before' => 7,
            'refund_percent' => 50,
        ];
    }
}
