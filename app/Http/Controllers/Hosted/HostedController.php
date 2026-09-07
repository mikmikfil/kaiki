<?php

declare(strict_types=1);

namespace App\Http\Controllers\Hosted;

use App\Domain\Branding\Actions\GetBrandPayload;
use App\Domain\Tenancy\Resolvers\HostedSlugResolver;
use App\Http\Middleware\HostedPageHeaders;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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
