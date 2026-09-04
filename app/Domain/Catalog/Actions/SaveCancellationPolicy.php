<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Models\CancellationPolicy;
use App\Models\CancellationPolicyTier;
use Illuminate\Support\Facades\DB;

/**
 * Save a policy and its ladder as one thing (spec CAT-13, CXL-3).
 *
 * The panel calls this and so will the importer. Two invariants live here
 * because neither is a form concern and both are wrong to enforce twice.
 *
 * ## Exactly one default per tenant
 *
 * `products.cancellation_policy_id` is nullable and null means "the tenant
 * default" (§2.3), so a tenant with two defaults has products whose terms
 * depend on which row a query returned first, and a tenant with none has
 * products with no cancellation terms at all. Neither is a state a guest should
 * be able to reach.
 *
 * Enforced in the application rather than by a partial unique index: SQLite and
 * MySQL disagree about those, and ENV-12 forbids branching on the driver.
 * Promoting a policy demotes the previous default **in the same transaction**,
 * so there is no instant with two.
 *
 * ## The ladder is replaced, not merged
 *
 * A repeater hands back the tiers the operator sees, and a rung they deleted is
 * a rung that should be gone. Merging would leave a threshold nobody can see
 * still deciding refunds. Safe to delete because every booking already holds
 * its own frozen copy (CXL-1) — that is the whole point of the snapshot.
 */
final class SaveCancellationPolicy
{
    /**
     * @param  array<string, mixed>  $attributes  already validated by the caller
     * @param  list<array{days_before: int|string, refund_percent: int|string}>|null  $tiers
     *                                                                                        null leaves the existing ladder alone; an empty array clears it
     */
    public function __invoke(CancellationPolicy $policy, array $attributes, ?array $tiers = null): CancellationPolicy
    {
        return DB::transaction(function () use ($policy, $attributes, $tiers): CancellationPolicy {
            $policy->fill($attributes);
            $policy->save();

            if ($policy->is_default) {
                $this->demoteOtherDefaults($policy);
            }

            // A tenant's first policy is its default whether or not anyone
            // ticked the box. Otherwise the first product created before
            // anybody thinks about cancellation terms has none at all.
            if (! $policy->is_default && ! $this->tenantHasDefault($policy)) {
                $policy->forceFill(['is_default' => true])->save();
            }

            if ($tiers !== null) {
                $this->replaceTiers($policy, $tiers);
            }

            return $policy->refresh();
        });
    }

    private function demoteOtherDefaults(CancellationPolicy $policy): void
    {
        CancellationPolicy::query()
            ->whereKeyNot($policy->getKey())
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    private function tenantHasDefault(CancellationPolicy $policy): bool
    {
        return CancellationPolicy::query()
            ->whereKeyNot($policy->getKey())
            ->where('is_default', true)
            ->exists();
    }

    /**
     * @param  list<array{days_before: int|string, refund_percent: int|string}>  $tiers
     */
    private function replaceTiers(CancellationPolicy $policy, array $tiers): void
    {
        $policy->tiers()->delete();

        foreach ($tiers as $tier) {
            CancellationPolicyTier::query()->create([
                'cancellation_policy_id' => $policy->getKey(),
                'days_before' => (int) $tier['days_before'],
                'refund_percent' => (int) $tier['refund_percent'],
            ]);
        }
    }
}
