<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\ApiErrorResponse;
use App\Support\Locale\LocaleResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the request locale from the I18N-5 chain, and remembers an explicit one.
 *
 * **Registered once, and ordered by the middleware priority list** rather than
 * by where it appears in any route's middleware array — see the comment in
 * `bootstrap/app.php`. It has to run after `ResolveTenant`, and in a Filament
 * panel the two land in different stacks: this one in `middleware`, so the
 * login page is not stuck in English for an operator who has not signed in
 * yet, and `ResolveTenant` in `authMiddleware`, because it resolves from the
 * signed-in user.
 *
 * Registering it in both stacks does not work — `Router::uniqueMiddleware`
 * keeps only the first occurrence — and the resulting bug is invisible, since
 * the page still renders, just in the wrong language.
 */
final class SetLocale
{
    public function __construct(private readonly LocaleResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $isApi = $request->is('api/v1', 'api/v1/*');

        if ($isApi && ($rejected = $this->resolver->unsupportedApiLocale($request)) !== null) {
            // `docs/api.md` §3.1. Refused before the controller, and before the
            // locale is set, so nothing downstream can quietly answer in a
            // language nobody asked for.
            return ApiErrorResponse::fromKey(
                key: 'api.errors.unsupported_locale',
                code: 'unsupported_locale',
                status: 400,
                details: [
                    'requested' => $rejected,
                    'supported' => LocaleResolver::permitted(),
                ],
            );
        }

        $locale = $this->resolver->resolve($request);

        app()->setLocale($locale);
        $this->remember($request, $locale);

        $response = $next($request);

        if ($isApi) {
            $this->declareLanguage($response, $locale);
        }

        return $response;
    }

    /**
     * Tell caches which language they are holding (`docs/api.md` §3.1).
     *
     * Two headers, and the second is the load-bearing one. `Content-Language`
     * only labels the body; **`Vary` is what stops a shared cache serving a
     * Greek payload to an English page**, and a CDN in front of this API is the
     * normal deployment rather than an exotic one.
     *
     * `Origin` and `X-Kaiki-Key` are on the list for the same reason: the CORS
     * headers and the whole payload are per-key, so a response cached against
     * one operator's key must never be replayed for another's.
     *
     * Merged, never set. {@see ApiKeyCors} is prepended
     * globally, which makes it the *outermost* middleware and therefore the
     * last to touch the response — a plain `set('Vary', …)` here would be
     * silently overwritten on its way out, and a cache bug that only appears
     * behind a CDN is one nobody reproduces locally.
     */
    private function declareLanguage(Response $response, string $locale): void
    {
        $response->headers->set('Content-Language', $locale);

        $existing = array_filter(array_map(
            trim(...),
            explode(',', (string) $response->headers->get('Vary', '')),
        ));

        $response->headers->set('Vary', implode(', ', array_values(array_unique([
            ...$existing,
            'Accept-Language',
            'Origin',
            'X-Kaiki-Key',
        ]))));
    }

    /**
     * Persist a locale the visitor explicitly asked for in the URL.
     *
     * Only `?lang=` is remembered. Persisting the *resolved* locale would write
     * the tenant default into the session on the very first request, which then
     * outranks `Accept-Language` for the rest of the visit — the chain would
     * silently collapse to its fifth step after one page view.
     *
     * **The session, and nothing else.** An earlier version also wrote
     * `users.locale`, which the #12 security review correctly called out: this
     * runs on a GET with no CSRF token, so an `<img src=".../app?lang=en">` on
     * any page an authenticated operator loads would permanently flip their
     * saved panel language — surviving the session, the browser and the device,
     * with nothing to attribute it to. Nothing in I18N-5 asked for an account
     * write; the chain stops at "requested locale". Saving a preference onto
     * the account belongs behind a POST on a profile screen (M7).
     */
    private function remember(Request $request, string $locale): void
    {
        $asked = $this->resolver->normalise($request->query(LocaleResolver::QUERY_KEY));

        // Not just "was `?lang=` present" — "did it get what it asked for".
        // A rejected `?lang=fr` must not be remembered as the tenant default.
        if ($asked === null || $asked !== $locale) {
            return;
        }

        if ($request->hasSession()) {
            $request->session()->put(LocaleResolver::SESSION_KEY, $locale);
        }
    }
}
