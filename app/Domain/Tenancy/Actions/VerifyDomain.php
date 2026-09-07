<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Support\DnsLookup;
use App\Enums\DomainStatus;
use App\Http\Controllers\TlsAskController;
use App\Models\TenantDomain;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Does this hostname actually point at us (HOS-3, ADR-0010 Option A)?
 *
 * ## Verified means the DNS says so, not that somebody pressed a button
 *
 * The operator adds a CNAME at their registrar; this asks the internet whether
 * they did. Nothing is served on an unverified hostname and — more importantly —
 * {@see TlsAskController} will not ask for a certificate
 * for one, which is the security property of the whole feature.
 *
 * ## A domain that stops resolving keeps serving
 *
 * The acceptance criterion is explicit and it is the humane reading: *"A
 * registrar glitch must not take an operator's site down."* So a **failed check
 * on an already-verified domain is recorded and reported, and the status does
 * not move**. DNS is not reliable enough to be a kill switch — a resolver
 * hiccup, a registrar's maintenance window, a propagation delay after an
 * unrelated record change, all of them look identical to "the operator removed
 * the CNAME", and only one of those is worth taking a site down for.
 *
 * A domain that is still `pending` is a different matter: it never worked, so
 * failing to resolve moves it to `failed`, where the panel can tell the operator
 * their CNAME is not right yet.
 */
class VerifyDomain
{
    public function __construct(private readonly DnsLookup $dns) {}

    /**
     * Check one domain and record what was found.
     *
     * @return bool whether the hostname currently points at the platform
     */
    public function __invoke(TenantDomain $domain): bool
    {
        $points = $this->pointsAtPlatform($domain->hostname);

        $domain->forceFill(['last_checked_at' => now()]);

        if ($points) {
            // First success, or a re-confirmation. Either way the row is
            // verified and the cached negative lookup has to go, or the page
            // stays a 404 for up to a minute after the operator's DNS lands.
            $domain->forceFill([
                'status' => DomainStatus::Verified,
                'verified_at' => $domain->verified_at ?? now(),
            ])->save();

            $this->forgetCache($domain->hostname);

            return true;
        }

        if ($domain->status === DomainStatus::Verified) {
            // It worked before and does not answer now. Recorded, reported, and
            // **still served** — see the class docblock.
            $domain->save();

            Log::warning('A verified custom domain stopped resolving.', [
                'tenant_id' => $domain->tenant_id,
                'hostname' => $domain->hostname,
                'verified_at' => $domain->verified_at?->toIso8601String(),
            ]);

            return false;
        }

        $domain->forceFill(['status' => DomainStatus::Failed])->save();

        return false;
    }

    /**
     * The hostnames a CNAME may legitimately point at.
     *
     * The hosted host is the documented target. The apex is accepted too,
     * because an operator whose registrar refuses a CNAME on a subdomain will
     * have been told to use an A record at the platform's address, and both
     * arrive here as strings to compare.
     *
     * @return list<string>
     */
    public function acceptableTargets(): array
    {
        $targets = [
            (string) config('kaiki.tenancy.hosted_host'),
            (string) config('kaiki.tenancy.custom_domain_target'),
        ];

        return array_values(array_filter(array_map(
            static fn (string $target): string => strtolower(trim(rtrim($target, '.'))),
            $targets,
        )));
    }

    private function pointsAtPlatform(string $hostname): bool
    {
        $acceptable = $this->acceptableTargets();

        if ($acceptable === []) {
            // Nothing configured to point at. Refusing rather than accepting
            // everything: an unconfigured platform must not verify domains.
            return false;
        }

        foreach ($this->dns->targetsFor($hostname) as $target) {
            if (in_array($target, $acceptable, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The resolver caches the hostname → tenant lookup for a minute.
     *
     * Clearing it here is what makes verification feel immediate to the operator
     * who just pressed the button, rather than "it started working a minute
     * later, I do not know why".
     */
    private function forgetCache(string $hostname): void
    {
        Cache::forget("tenant:host:{$hostname}");
    }
}
