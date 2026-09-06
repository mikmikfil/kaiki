<?php

declare(strict_types=1);

namespace App\Http\Controllers\Guest;

use App\Domain\Branding\Actions\GetBrandPayload;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the four token pages share (spec TOK-3 … TOK-5).
 *
 * ## The failure page is one method, and that is the requirement
 *
 * TOK-4: a failure is *"a generic branded 'link not valid' page in the guest
 * locale, never a distinction between 'not found' and 'expired'."* Every
 * controller returns {@see self::linkNotValid()} and there is nowhere else a
 * different sentence could be written — which is what makes
 * `TokenSecurityTest`'s byte-identical assertion hold rather than merely pass
 * today.
 *
 * It has no tenant, because a token that did not resolve has no tenant to have.
 * The page is branded with the platform's own defaults, which is the honest
 * rendering: we do not know whose guest this is.
 *
 * ## The locale comes from the booking, and `?lang=` overrides it
 *
 * TOK-5, in that order. A guest who booked in Greek gets Greek without asking,
 * and the same guest forwarding the link to an English-speaking partner gets a
 * `?lang=en` that works — which is the whole reason the override exists rather
 * than the browser's `Accept-Language`, since the person reading the page is
 * often not the person whose browser it is.
 *
 * Only `el` and `en` are honoured. An unknown `?lang=` falls back rather than
 * refusing: a mistyped query string must not cost a guest their booking page.
 */
abstract class GuestPageController
{
    public function __construct(protected readonly GetBrandPayload $brand) {}

    /**
     * The locale this page renders in, and the application set to it.
     *
     * Setting `app()->setLocale()` rather than only returning a string, because
     * everything the view reaches for — `__()`, the translatable accessors on a
     * product, a formatted date — reads the application locale and not a
     * variable somebody remembered to pass.
     */
    protected function resolveLocale(Request $request, string $bookingLocale): string
    {
        $requested = $request->query('lang');
        $supported = ['el', 'en'];

        $locale = is_string($requested) && in_array($requested, $supported, true)
            ? $requested
            : (in_array($bookingLocale, $supported, true) ? $bookingLocale : 'el');

        app()->setLocale($locale);

        return $locale;
    }

    /**
     * The brand payload for a tenant, read inside that tenant.
     *
     * `BrandProfile` is tenant-owned, so the payload cannot be built from
     * outside one — and these pages resolved their tenant from a token rather
     * than from a host or a key, so the entry has to be explicit.
     *
     * @return array<string, mixed>
     */
    protected function brandFor(Tenant $tenant, string $locale): array
    {
        return Tenancy::forTenant($tenant, fn (): array => ($this->brand)($tenant, $locale));
    }

    /**
     * TOK-4's generic refusal, identical for every reason.
     *
     * 404 rather than 403 or 410: each of the other two would say something.
     * `410 Gone` in particular would confirm that the token once existed, which
     * is precisely the distinction the requirement forbids.
     */
    protected function linkNotValid(Request $request): Response
    {
        $this->resolveLocale($request, 'el');

        return response()->view('guest.link-not-valid', [], Response::HTTP_NOT_FOUND);
    }

    /**
     * Render a guest page **inside** its tenant, and return the finished HTML.
     *
     * `response()->view()` defers rendering until the response is sent, which
     * is long after `Tenancy::forTenant()` has handed the context back — and
     * the first lazy `$booking->product` in the template then throws
     * `TenantContextMissingException`. That is the tenancy scope working
     * exactly as #8 intended; the mistake is rendering outside the tenant, not
     * the scope catching it.
     *
     * Eager-loading everything the template touches would be the other fix, and
     * it is the fragile one: it works until somebody adds `$booking->vessel` to
     * the view. Rendering inside the tenant is true for whatever the template
     * reaches for.
     *
     * @param  callable(): array<string, mixed>  $data
     */
    protected function renderInTenant(Tenant $tenant, string $view, callable $data): Response
    {
        $html = Tenancy::forTenant($tenant, static fn (): string => view($view, $data())->render());

        return response($html);
    }
}
