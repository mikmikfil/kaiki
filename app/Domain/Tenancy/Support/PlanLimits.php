<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Support;

use App\Enums\Plan;
use App\Models\Tenant;
use App\Models\Vessel;

/**
 * What an operator's plan lets them create (spec SAA-3, SAA-8).
 *
 * > **SAA-8** Plan limits are enforced at the point of creation with a clear
 * > upgrade path: creating a sixth vessel on `Fleet` is blocked with a
 * > localised message and an upgrade link, never silently truncated.
 *
 * {@see Plan} has carried the three rules since M0 and nothing read them, so a
 * `Solo` operator could build ten boats and a custom domain. This is the one
 * place the panel asks — the vessel form, the domain screen and the webhook
 * screen all come here rather than each reading the enum their own way.
 *
 * ## At the point of creation, and nowhere else
 *
 * "Never silently truncated" is the other half of SAA-8. An operator who is
 * moved down a plan keeps every boat, domain and webhook they already have:
 * nothing is deleted, hidden or stopped. They cannot add another until they are
 * back under the limit or on a larger plan. A downgrade that switched off a
 * verified domain would take a business's website down on a billing change.
 *
 * ## Not in a model observer
 *
 * The demo seeders, the importer and every factory create vessels directly, and
 * a limit enforced in the model would make each of them fail on a plan rule
 * that is about what a *person* may do in the panel. The rule is asked where a
 * person asks to create.
 */
final class PlanLimits
{
    /** How many vessels this operator has, counted under their own tenancy. */
    public static function vesselCount(): int
    {
        return Vessel::query()->count();
    }

    /** Null means the plan has no vessel limit. */
    public static function vesselLimit(Tenant $tenant): ?int
    {
        return $tenant->plan->vesselLimit();
    }

    public static function canAddVessel(Tenant $tenant): bool
    {
        $limit = self::vesselLimit($tenant);

        return $limit === null || self::vesselCount() < $limit;
    }

    public static function canUseCustomDomain(Tenant $tenant): bool
    {
        return $tenant->plan->allowsCustomDomain();
    }

    public static function canUseWebhooks(Tenant $tenant): bool
    {
        return $tenant->plan->allowsWebhooks();
    }

    /** The sentence shown when a vessel would go over the plan. */
    public static function vesselLimitMessage(Tenant $tenant): string
    {
        $limit = (int) self::vesselLimit($tenant);

        return (string) __('plans.vessels.reached', [
            'plan' => $tenant->plan->label(),
            'limit' => trans_choice('plans.vessels.count', $limit, ['count' => $limit]),
        ]);
    }

    /** "3 of 5 boats on your plan", or null when the plan has no limit. */
    public static function vesselUsage(Tenant $tenant): ?string
    {
        $limit = self::vesselLimit($tenant);

        if ($limit === null) {
            return null;
        }

        return (string) __('plans.vessels.usage', [
            'count' => self::vesselCount(),
            'limit' => trans_choice('plans.vessels.count', $limit, ['count' => $limit]),
            'plan' => $tenant->plan->label(),
        ]);
    }

    /**
     * Where "upgrade" goes.
     *
     * There is no billing screen until the Viva subscriptions are built (M7),
     * so this is a configured address — `KAIKI_UPGRADE_URL`, a contact page or a
     * `mailto:` — and falls back to writing to the platform. When billing
     * arrives it becomes that screen's URL and nothing that links here moves.
     */
    public static function upgradeUrl(): string
    {
        $configured = config('kaiki.plans.upgrade_url');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return 'mailto:' . (string) config('mail.from.address');
    }
}
