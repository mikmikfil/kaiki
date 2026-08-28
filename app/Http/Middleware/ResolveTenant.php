<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\Resolvers\ApiKeyResolver;
use App\Domain\Tenancy\Resolvers\CustomDomainResolver;
use App\Domain\Tenancy\Resolvers\HostedSlugResolver;
use App\Domain\Tenancy\Resolvers\PanelSessionResolver;
use App\Domain\Tenancy\Resolvers\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves exactly one tenant per request (spec TEN-4).
 *
 * The order is fixed and stops at the first match:
 *
 *   1. API key      — the caller named the tenant explicitly
 *   2. custom domain — a verified hostname the operator owns
 *   3. hosted slug   — `book.{platform-domain}/{slug}`
 *   4. panel session — the signed-in user's own tenant
 *
 * **No match aborts with 404.** There is deliberately no default tenant and no
 * fallback: ADR-0001 treats a fallback as a tenant-isolation defect, because
 * the alternative to "I don't know which operator this is" is serving somebody
 * else's data. A 404 is also the right answer to the outside world — it does
 * not reveal whether a slug or hostname exists.
 */
final class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        foreach ($this->resolvers() as $resolver) {
            $tenant = $resolver->resolve($request);

            if ($tenant === null) {
                continue;
            }

            tenancy()->initialize($tenant);

            $request->attributes->set('tenant_resolved_by', $resolver->name());

            return $next($request);
        }

        abort(404);
    }

    /** @return list<TenantResolver> */
    private function resolvers(): array
    {
        return [
            app(ApiKeyResolver::class),
            app(CustomDomainResolver::class),
            app(HostedSlugResolver::class),
            app(PanelSessionResolver::class),
        ];
    }
}
