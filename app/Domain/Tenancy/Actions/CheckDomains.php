<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Actions;

use App\Enums\DomainStatus;
use App\Models\TenantDomain;
use App\Support\Tenancy;

/**
 * The sweep that finishes what an operator started (HOS-3, ADR-0010).
 *
 * ## Verification cannot be synchronous only
 *
 * An operator adds a CNAME at their registrar and it propagates in anything from
 * a minute to a day. A flow that only checked when they pressed a button would
 * leave them pressing it, or — far more likely — leave them concluding the
 * feature is broken and telephoning about it.
 *
 * So this runs on a schedule beside the other sweeps, and the panel's button is
 * a convenience for the operator who is still sitting there.
 *
 * ## What it checks, and what it leaves alone
 *
 * `pending` and `failed` rows are the ones waiting for DNS. `verified` rows are
 * re-checked too, because a domain that stops resolving is worth **recording and
 * reporting** — but {@see VerifyDomain} does not un-verify one, for the reason
 * its own docblock gives: a registrar glitch must not take an operator's site
 * down. `disabled` is the operator's own decision and is not re-litigated by a
 * cron job.
 */
class CheckDomains
{
    public function __construct(private readonly VerifyDomain $verify) {}

    /**
     * @return array{checked: int, verified: int}
     */
    public function __invoke(): array
    {
        // Across every tenant: the sweep runs from the scheduler, where no
        // tenant is resolved and none should be.
        $domains = Tenancy::withoutTenancy(
            static fn () => TenantDomain::query()
                ->whereIn('status', [DomainStatus::Pending, DomainStatus::Failed, DomainStatus::Verified])
                ->orderBy('id')
                ->get(),
        );

        $verified = 0;

        foreach ($domains as $domain) {
            if (($this->verify)($domain)) {
                $verified++;
            }
        }

        return ['checked' => $domains->count(), 'verified' => $verified];
    }
}
