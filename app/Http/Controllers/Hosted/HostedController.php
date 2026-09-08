<?php

declare(strict_types=1);

namespace App\Http\Controllers\Hosted;

use App\Domain\Branding\Actions\GetBrandPayload;
use App\Domain\Hosted\Support\HostedEmbedToken;
use App\Domain\Hosted\Support\HostedHost;
use App\Domain\Tenancy\Resolvers\HostedSlugResolver;
use App\Enums\DomainStatus;
use App\Http\Middleware\HostedPageHeaders;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * What every hosted page does the same way (spec HOS-4, HOS-5, HOS-6, HOS-10).
 *
 * Extracted in #104, when the product page became the second controller that
 * had to resolve the same tenant, pick the same locale, build the same
 * `hreflang` alternates and share the same five view variables. The alternative
 * was a second copy, and the failure mode of a second copy here is a page that
 * quietly stops carrying the read-only notice or the nonce — neither of which
 * is visible until it matters.
 *
 * ## The tenant is already resolved, and that is why this is short
 *
 * TEN-4's third strategy reads the first path segment as an operator slug **on
 * the hosted host only** ({@see HostedSlugResolver}, #7), and declines one whose
 * page is switched off — so the `tenant` middleware has resolved the operator
 * and produced HOS-6's 404 before a method here runs.
 *
 * That is also why nothing here wraps a `Tenancy::forTenant()` around the
 * render. The token pages of #86 must, because a token resolves its own tenant
 * and nothing set one; here the context is live for the whole request, so a
 * lazy relation in a template resolves the way it does everywhere else.
 *
 * ## The locale is the visitor's, and it is in the URL
 *
 * HOS-5 wants `hreflang` alternates and a canonical per locale, which only
 * works if each locale has a URL of its own. `?lang=` is that URL — the same
 * parameter the token pages use, so a guest moving between the two surfaces
 * does not meet two conventions.
 */
abstract class HostedController
{
    public function __construct(
        protected readonly GetBrandPayload $brand,
    ) {}

    /**
     * Render inside the tenant, with everything the layout needs.
     *
     * @param  callable(): array<string, mixed>  $data
     */
    protected function render(Request $request, Tenant $tenant, string $view, callable $data, string $locale): Response
    {
        // HOS-7, before anything is composed: there is no point building a page
        // whose whole job is to be replaced by a redirect.
        $redirect = $this->customDomainRedirect($request, $tenant);

        if ($redirect !== null) {
            return $redirect;
        }

        $shared = [
            'tenant' => $tenant,
            'locale' => $locale,
            'brand' => ($this->brand)($tenant, $locale),
            'nonce' => (string) $request->attributes->get(HostedPageHeaders::NONCE, ''),
            // HOS-10: the page is served in full and the booking area says why
            // it is not there. A guest is never punished for an operator's
            // billing failure.
            'readOnly' => ! $tenant->allowsWrites(),
            // Brand decision 6 of 2026-09-04, from a flag rather than the
            // template, so a white-label tier can remove it without a deploy.
            'poweredBy' => (bool) config('kaiki.hosted.powered_by', true),
            'alternates' => $this->alternates($request),
            // The credential the page hands its own widget, minted per
            // response and stored nowhere. See HostedEmbedToken: the platform
            // keeps only a hash of a publishable key, so a hosted page has no
            // key it could render — which is why these pages carried a mount
            // point and no script for two milestones.
            'embedToken' => HostedEmbedToken::issue($tenant),
        ];

        return response(view($view, [...$shared, ...$data()])->render());
    }

    /**
     * The `hreflang` alternates and the canonical, one per locale (HOS-5).
     *
     * Built from the current URL rather than from a route name, so a page added
     * later gets its alternates for free instead of getting them wrong — which
     * is exactly what happened when the product page arrived: it inherited
     * correct alternates without a line of its own.
     *
     * @return array<string, string>
     */
    protected function alternates(Request $request): array
    {
        $alternates = [];

        foreach (['el', 'en'] as $locale) {
            $alternates[$locale] = $request->fullUrlWithQuery(['lang' => $locale]);
        }

        return $alternates;
    }

    /**
     * The visitor's locale: `?lang=`, then the operator's default (I18N-5).
     *
     * The browser's `Accept-Language` is deliberately not consulted. A Greek
     * operator's page opened by a German tourist should be Greek or English by
     * the operator's choice, and the switch in the nav is how the visitor says
     * otherwise — a header that silently picked German would show them a page
     * in a language the operator has not written.
     */
    protected function resolveLocale(Request $request, Tenant $tenant): string
    {
        $requested = $request->query('lang');

        $locale = is_string($requested) && in_array($requested, ['el', 'en'], true)
            ? $requested
            : (in_array($tenant->default_locale, ['el', 'en'], true) ? $tenant->default_locale : 'el');

        app()->setLocale($locale);

        return $locale;
    }

    /**
     * HOS-7: the platform URL 301s to the operator's own domain.
     *
     * *"When a custom domain is active, the `book.{platform-domain}/{slug}` URL
     * issues a 301 redirect to the custom domain so the two never compete in
     * search."* Two addresses serving identical pages is the duplicate-content
     * problem a canonical tag only half solves — a permanent redirect moves the
     * ranking rather than splitting it.
     *
     * **301 and not 302**, deliberately: a temporary redirect tells a search
     * engine to keep the platform URL indexed, which is exactly the competition
     * the requirement exists to end. The cost of being wrong is that browsers
     * cache it, which is also the point.
     *
     * Only a **verified** domain redirects. A pending one is a hostname nobody
     * is serving yet, and redirecting to it would take the operator's page down
     * until their registrar catches up.
     */
    protected function customDomainRedirect(Request $request, Tenant $tenant): ?RedirectResponse
    {
        $host = strtolower($request->getHost());
        $hosted = HostedHost::name();

        // Already on the custom domain, or on a host that is not the platform's
        // hosted host at all. Nothing to move.
        if ($host !== $hosted) {
            return null;
        }

        $domain = Tenancy::withoutTenancy(static fn (): ?TenantDomain => TenantDomain::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('status', DomainStatus::Verified)
            ->orderBy('id')
            ->first());

        if ($domain === null) {
            return null;
        }

        // The path and query travel with it: a link to one trip on the platform
        // host must land on that trip, not on the operator's home page. The
        // slug segment is dropped, because on a custom domain the operator *is*
        // the site — `/{slug}/sunset` becomes `/sunset`.
        $path = ltrim((string) $request->getPathInfo(), '/');
        $withoutSlug = preg_replace('#^' . preg_quote($tenant->slug, '#') . '(/|$)#', '', $path) ?? '';
        $query = $request->getQueryString();

        return redirect()->away(
            sprintf('https://%s/%s%s', $domain->hostname, $withoutSlug, $query === null ? '' : '?' . $query),
            SymfonyResponse::HTTP_MOVED_PERMANENTLY,
        );
    }

    /**
     * The tenant the `tenant` middleware already resolved.
     *
     * Reading it back rather than looking it up again is what keeps there being
     * one answer.
     */
    protected function tenant(): Tenant
    {
        $tenant = Tenancy::current();

        abort_unless($tenant instanceof Tenant, Response::HTTP_NOT_FOUND);

        return $tenant;
    }
}
