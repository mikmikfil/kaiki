<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Tenancy\Actions\VerifyDomain;
use App\Enums\DomainStatus;
use App\Models\TenantDomain;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Caddy's on-demand TLS ask endpoint (HOS-3, ADR-0010 Option A).
 *
 * ## The only genuinely dangerous surface in this feature
 *
 * On-demand TLS means the server obtains a certificate for **whatever hostname
 * arrives**, provided this endpoint approves it. An endpoint that answered
 * broadly would let a stranger point any DNS record at the platform and make it
 * request certificates until Let's Encrypt's rate limit is exhausted — for the
 * whole platform, not for them. That is a denial of service on every operator at
 * once, executed with a DNS record and a browser.
 *
 * So the rule is one sentence with no exceptions: **yes only for a hostname that
 * is `verified` in `tenant_domains`.** Not pending, not failed, not disabled, and
 * not "a hostname belonging to a tenant" — the row's own status, which only
 * {@see VerifyDomain} sets, and only after the DNS
 * said so.
 *
 * `TlsAskEndpointTest` asserts the **refusal** first. An approval test passing
 * says nothing about the property that matters.
 *
 * ## Why the answer is a bare status code
 *
 * Caddy reads the status and nothing else: 200 means issue, anything else means
 * do not. A body would be a payload nobody parses and a place to leak whether a
 * hostname is known — a 404 with "no such domain" and a 403 with "not verified"
 * are a probe oracle for somebody enumerating operators. Both refusals are the
 * same empty 403.
 *
 * ## Outside the tenant scope, deliberately
 *
 * The lookup crosses tenants by construction: it is asked about a hostname
 * before any tenant is resolved, which is the same reason
 * `CustomDomainResolver` reads without tenancy. `Tenancy::withoutTenancy()`
 * makes that explicit rather than leaving it to whether a scope happened to be
 * applied.
 */
final class TlsAskController
{
    public function __invoke(Request $request): Response
    {
        $hostname = TenantDomain::normalise((string) $request->query('domain', ''));

        if ($hostname === '') {
            return response('', Response::HTTP_FORBIDDEN);
        }

        $verified = Tenancy::withoutTenancy(static fn (): bool => TenantDomain::query()
            ->where('hostname', $hostname)
            ->where('status', DomainStatus::Verified)
            ->exists());

        // No body either way: Caddy reads the code, and a message would be a
        // probe oracle telling a stranger which hostnames the platform knows.
        return response('', $verified ? Response::HTTP_OK : Response::HTTP_FORBIDDEN);
    }
}
