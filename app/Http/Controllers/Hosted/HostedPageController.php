<?php

declare(strict_types=1);

namespace App\Http\Controllers\Hosted;

use App\Domain\Branding\Actions\GetBrandPayload;
use App\Domain\Tenancy\Resolvers\HostedSlugResolver;
use App\Enums\ProductStatus;
use App\Http\Middleware\HostedPageHeaders;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `book.{platform-domain}/{operator-slug}` — the operator's own page (HOS-1).
 *
 * ## Everything renders without JavaScript
 *
 * HOS-4: *"Hosted pages are server-rendered Blade, work without JavaScript for
 * all content, and mount the widget only for the booking interaction."* So
 * this is Blade with no build step and no hydration, and the only thing that
 * can be missing when scripts are blocked is the date picker.
 *
 * That is not a nicety. A crawler runs no JavaScript, and HOS-2's whole point
 * is that these pages are what search engines see; a visitor in a harbour on
 * one bar of signal is the other half.
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
class HostedPageController
{
    public function __construct(private readonly GetBrandPayload $brand) {}

    /**
     * The operator's landing page.
     *
     * **The trips list is the placeholder the block system replaces.** The
     * editable home page is its own issue; until it lands, an operator's page
     * shows who they are and what they sell, which is the useful half and the
     * default that issue will fall back to anyway.
     */
    public function index(Request $request): Response
    {
        $tenant = $this->tenant();
        $locale = $this->resolveLocale($request, $tenant);

        return $this->render($request, $tenant, 'hosted.index', fn (): array => [
            'products' => Product::query()
                ->where('status', ProductStatus::Active)
                ->with(['vessel', 'meetingPoint'])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(),
        ], $locale);
    }

    /** The operator's legal and policy page (HOS-9). */
    public function legal(Request $request): Response
    {
        $tenant = $this->tenant();
        $locale = $this->resolveLocale($request, $tenant);

        return $this->render($request, $tenant, 'hosted.legal', static fn (): array => [], $locale);
    }

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

        // Already inside the tenant — the middleware resolved it — so this
        // renders directly rather than re-entering. The token pages of #86 have
        // to wrap, because a token resolves its own tenant and nothing set one.
        return response(view($view, [...$shared, ...$data()])->render());
    }

    /**
     * The `hreflang` alternates and the canonical, one per locale (HOS-5).
     *
     * Built from the current URL rather than from a route name, so a page added
     * later gets its alternates for free instead of getting them wrong.
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
     * TEN-4's third strategy ({@see HostedSlugResolver})
     * reads the first path segment as a slug **on the hosted host only**, and
     * declines an operator whose page is switched off — so by the time a
     * controller runs, the tenant is resolved and HOS-6's 404 has already
     * happened. Reading it back here rather than looking it up again is what
     * keeps there being one answer.
     */
    protected function tenant(): Tenant
    {
        $tenant = Tenancy::current();

        abort_unless($tenant instanceof Tenant, Response::HTTP_NOT_FOUND);

        return $tenant;
    }
}
