<?php

declare(strict_types=1);

namespace App\Support\Locale;

use App\Http\Middleware\SetLocale;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Http\Request;

/**
 * The one implementation of the I18N-5 fallback order.
 *
 * ADR-0008's acceptance note is explicit: *"Locale fallback order is fixed and
 * must be implemented once, in the locale middleware. Nothing else may
 * re-implement fallback."* So this class is the only place that knows the
 * order, and {@see SetLocale} is a thin caller.
 *
 * The spec states the order as "requested locale, then tenant `default_locale`,
 * then `en`". "Requested" is four signals that can disagree, so the full order
 * is:
 *
 *   1. `?lang=`               an explicit act, right now
 *   2. session                an explicit act, a moment ago
 *   3. `users.locale`         an explicit choice, saved earlier
 *   4. `Accept-Language`      a browser default they may never have set
 *   5. `tenants.default_locale`
 *   6. `en`
 *
 * A deliberate click must outrank a browser header the operator has never
 * looked at — a Greek operator on a corporate laptop imaged in English is a
 * completely ordinary situation.
 *
 * Nothing here throws. An unsupported locale falls through to the next signal,
 * because a stray `?lang=fr` in a link somebody shared must not break the page.
 */
final class LocaleResolver
{
    /**
     * Always installed, so the chain always terminates.
     *
     * Not read from `config('app.locale')`: that is the *default* locale and is
     * environment-settable, which would make the last resort of the fallback
     * chain something an env var could remove.
     */
    public const FALLBACK = 'en';

    public const QUERY_KEY = 'lang';

    /**
     * The public API's spelling of the same override (`docs/api.md` §3.1).
     *
     * Two keys rather than one because they are two contracts with two
     * audiences and neither may be renamed: `?lang=` is in operators' own links
     * and in the panel's language switcher, and `?locale=` is what §3.1 and the
     * `LocaleQuery` parameter promise every API client — the WordPress plugin
     * passes it when WPML has already decided the page language.
     *
     * Both are step 1 of the I18N-5 chain, and the API spelling is checked
     * first so that a request carrying both is answered by the one the machine
     * client meant.
     */
    public const API_QUERY_KEY = 'locale';

    public const SESSION_KEY = 'locale';

    public function resolve(Request $request): string
    {
        $installed = self::installed();
        $tenant = Tenancy::current();
        $permitted = self::permitted();

        foreach ($this->requested($request) as $candidate) {
            $locale = $this->normalise($candidate);

            if ($locale !== null && in_array($locale, $permitted, true)) {
                return $locale;
            }
        }

        // The tenant's own default is checked against the *installed* locales
        // rather than against its own `supported_locales`. A tenant whose
        // default is missing from its own supported list is a data defect, and
        // the useful behaviour there is to honour the default anyway; what must
        // never happen is serving a locale whose lang files do not exist, which
        // renders as raw dotted keys on every line of the page.
        $default = $this->normalise($tenant?->default_locale);

        if ($default !== null && in_array($default, $installed, true)) {
            return $default;
        }

        return self::FALLBACK;
    }

    /**
     * The locales this application actually ships lang files for.
     *
     * @return list<string>
     */
    public static function installed(): array
    {
        /** @var list<string> $locales */
        $locales = (array) config('app.available_locales', [self::FALLBACK]);

        return array_values(array_filter($locales, 'is_string'));
    }

    /**
     * Reduce anything locale-shaped to a bare language subtag.
     *
     * `en-GB`, `en_US` and `EN` are all `en`. Region is dropped on purpose:
     * the application ships one Greek and one English translation, and treating
     * `en-AU` as unsupported would silently push an Australian visitor onto the
     * operator's Greek default.
     */
    public function normalise(mixed $candidate): ?string
    {
        if (! is_string($candidate) || $candidate === '') {
            return null;
        }

        $language = strtolower((string) preg_split('/[-_]/', $candidate)[0]);

        return preg_match('/^[a-z]{2}$/', $language) === 1 ? $language : null;
    }

    /**
     * The requested-locale signals, strongest first.
     *
     * @return list<mixed>
     */
    private function requested(Request $request): array
    {
        $user = $request->user();

        return [
            $request->query(self::API_QUERY_KEY),
            $request->query(self::QUERY_KEY),
            $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null,
            $user instanceof User ? $user->locale : null,
            ...$this->acceptLanguage($request),
        ];
    }

    /**
     * The browser's ordered language preferences.
     *
     * Deliberately not `getPreferredLanguage()`: Symfony returns the *first*
     * argument when nothing matches, so it can never report "no preference" —
     * every request would appear to have explicitly asked for Greek, and steps
     * 5 and 6 of the chain would be unreachable.
     *
     * @return list<string>
     */
    private function acceptLanguage(Request $request): array
    {
        return array_values(array_filter($request->getLanguages(), 'is_string'));
    }

    /**
     * An explicit `?locale=` this tenant will not serve, or null.
     *
     * The one asymmetry between the two surfaces, and it is deliberate.
     * `resolve()` never throws — a stray `?lang=fr` in a link somebody shared
     * must not break a public page. But `docs/api.md` §3.1 requires
     * `400 unsupported_locale` for an explicit `?locale=fr`, because *"a typo
     * must not silently serve Greek to a French page"*: a machine client that
     * asked for a language and got a different one has no way to notice.
     *
     * So the decision lives here, beside the order it belongs to, and
     * {@see SetLocale} is still the only caller — ADR-0008's *"nothing else may
     * re-implement fallback"* holds.
     *
     * An unmatched `Accept-Language` is deliberately not covered: §3.1 says it
     * "is **not** an error; it falls through to the tenant default".
     */
    public function unsupportedApiLocale(Request $request): ?string
    {
        $asked = $request->query(self::API_QUERY_KEY);

        if (! is_string($asked) || $asked === '') {
            return null;
        }

        $normalised = $this->normalise($asked);

        if ($normalised !== null && in_array($normalised, self::permitted(), true)) {
            return null;
        }

        return $asked;
    }

    /**
     * The locales a visitor may ask the current tenant for.
     *
     * **Public, and the only implementation of this narrowing.** The language
     * switcher needs exactly the same answer — a switcher offering a locale the
     * resolver will then refuse is a control that does nothing when pressed,
     * and two functions that "obviously" agree are how that ships. ADR-0008's
     * acceptance note is explicit that nothing may re-implement this.
     *
     * @return list<string>
     */
    public static function permitted(): array
    {
        $installed = self::installed();
        $tenant = Tenancy::current();

        if ($tenant === null) {
            return $installed;
        }

        // A literal JSON `null` in the column — a bad import, or a future admin
        // form — would otherwise TypeError inside array_map and 500 every panel
        // page *and* the login page, because the switcher calls this same path.
        if (! is_array($tenant->supported_locales)) {
            return $installed;
        }

        $resolver = new self;

        $supported = array_values(array_filter(
            array_map(static fn (mixed $l): ?string => $resolver->normalise($l), $tenant->supported_locales),
            is_string(...),
        ));

        // An operator selling only in Greek must not have their page
        // half-translated by a widget that asked for English.
        $narrowed = array_values(array_intersect($installed, $supported));

        // An empty or unusable `supported_locales` is a data defect, not an
        // instruction to serve nothing. Softening here rather than in the
        // caller means the resolver and the switcher soften identically —
        // when this lived in two places they disagreed, and the switcher
        // offered locales `resolve()` went on to refuse.
        return $narrowed === [] ? $installed : $narrowed;
    }

    /**
     * The locales a translatable field must be filled in before it may be saved.
     *
     * A **third** locale set, deliberately separate from {@see self::installed}
     * and {@see self::permitted}. `docs/data-model.md` §1.6 requires both `el`
     * and `en` on every translatable column; EXT-7 says adding `it` or `de`
     * must be *"a lang-file plus widget-bundle addition only"*. Derive this
     * from the installed locales and those two rules contradict each other —
     * shipping German lang files would instantly invalidate every product in
     * the database and lock operators out of their own catalogue.
     *
     * So the requirement is its own list, and growing it is a migration and a
     * backfill, not a config edit.
     *
     * @return list<string>
     */
    public static function required(): array
    {
        /** @var list<string> $locales */
        $locales = (array) config('kaiki.i18n.required_locales', [self::FALLBACK]);

        $resolver = new self;

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $l): ?string => $resolver->normalise($l), $locales),
            is_string(...),
        )));
    }
}
