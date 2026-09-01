<?php

declare(strict_types=1);

namespace App\Support\Locale;

use Illuminate\Http\Request;

/**
 * The locales offered by the switcher, for the request being rendered.
 *
 * A tenant that sells only in Greek gets no switcher at all rather than a
 * switcher that half-translates their page — the list is narrowed by
 * `supported_locales`, the same narrowing {@see LocaleResolver} applies when it
 * decides what to honour. Two different answers to "which languages does this
 * operator offer" is how a control appears that does nothing when pressed.
 */
final class LocaleOptions
{
    /**
     * @return list<array{code: string, label: string, short: string, url: string, current: bool}>
     */
    public static function forRequest(Request $request): array
    {
        $current = (string) app()->getLocale();
        $options = [];

        foreach (self::available() as $code) {
            $options[] = [
                'code' => $code,
                'label' => (string) __("enums.locale.{$code}.label"),
                'short' => (string) __("enums.locale.{$code}.short"),
                // Keeps every other query parameter, so switching language on a
                // filtered table does not silently reset the filters.
                'url' => $request->fullUrlWithQuery([LocaleResolver::QUERY_KEY => $code]),
                'current' => $code === $current,
            ];
        }

        return $options;
    }

    /**
     * Installed locales, narrowed to what this tenant offers.
     *
     * Delegates rather than deciding. This method had its own copy of the
     * narrowing until the #12 review: it lowercased where the resolver
     * normalised, and softened an empty list where the resolver did not, so a
     * tenant with `supported_locales = ['el','en-GB']` was offered one set and
     * permitted another.
     *
     * @return list<string>
     */
    public static function available(): array
    {
        return LocaleResolver::permitted();
    }
}
