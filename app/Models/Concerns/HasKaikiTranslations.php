<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Locale\LocaleResolver;
use App\Support\Locale\TranslationValue;
use App\Support\Tenancy;
use Spatie\Translatable\HasTranslations;

/**
 * `spatie/laravel-translatable` with the I18N-5 fallback chain attached.
 *
 * Every translatable model uses this rather than the package trait directly, so
 * that reading `$product->title` with no Greek translation behaves the same way
 * everywhere: **requested locale, then the tenant's `default_locale`, then
 * `en`** (spec I18N-5, CAT-6).
 *
 * ## Why not the package's own fallback
 *
 * `HasTranslations::normalizeLocale()` looks for a `getFallbackLocale()` method
 * and honours it — but that hook returns **one** locale, and I18N-5 has two
 * steps after the requested one. A Greek-default operator whose product has
 * only an English summary, and an English-default operator whose product has
 * only Greek, are both ordinary; a single fallback serves one of them and shows
 * the other an empty field.
 *
 * So the fallback is done here, calling the package once per candidate with
 * fallback *disabled*, and the package's own chain never runs. Attribute reads
 * go through `getAttributeValue()`, which calls `$this->getTranslation(...)` —
 * this override — so a plain `$product->title` gets the chain for free.
 *
 * ## Cost
 *
 * The requested locale is tried first and returns immediately when it has a
 * value, which is the overwhelmingly common case; the tenant is only consulted
 * when a translation is genuinely missing. A product list rendering forty rows
 * in the operator's own language does no extra work at all.
 *
 * The order itself is not restated here — {@see LocaleResolver} owns it, per
 * ADR-0008's acceptance note that *"nothing else may re-implement fallback"*.
 * What this trait adds is applying that order to *model attributes*, which the
 * middleware cannot do because it runs once per request and this runs once per
 * field.
 */
trait HasKaikiTranslations
{
    use HasTranslations {
        getTranslation as protected packageTranslation;
    }

    /**
     * The requested locale, then the I18N-5 fallbacks.
     *
     * Signature matches the package's so that every internal caller — the
     * attribute accessor, `translate()`, `getTranslationWithFallback()` — picks
     * this up unchanged.
     */
    public function getTranslation(string $key, string $locale, bool $useFallbackLocale = true): mixed
    {
        $value = $this->packageTranslation($key, $locale, false);

        if (! $useFallbackLocale || ! TranslationValue::isBlank($value)) {
            return $value;
        }

        foreach ($this->translationFallbackLocales($locale) as $candidate) {
            $fallback = $this->packageTranslation($key, $candidate, false);

            if (! TranslationValue::isBlank($fallback)) {
                return $fallback;
            }
        }

        // Nothing in any locale. Return the requested locale's empty value
        // rather than inventing one, so the shape stays whatever the package's
        // `allowNullForTranslation` setting says it should be.
        return $value;
    }

    /**
     * Steps 2 and 3 of I18N-5, in order, excluding the locale already tried.
     *
     * @return list<string>
     */
    public function translationFallbackLocales(string $requested): array
    {
        $resolver = new LocaleResolver;

        $candidates = [
            $resolver->normalise(Tenancy::current()?->default_locale),
            LocaleResolver::FALLBACK,
        ];

        $chain = [];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== $requested && ! in_array($candidate, $chain, true)) {
                $chain[] = $candidate;
            }
        }

        return $chain;
    }
}
