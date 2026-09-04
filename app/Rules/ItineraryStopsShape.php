<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Locale\LocaleResolver;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * `products.itinerary_stops` — the awkward one (`docs/data-model.md` §3.6).
 *
 * A translatable array of objects where **the labels are translatable and the
 * coordinates are not**. §3.6 solves that with a `_geo` sidecar keyed by a
 * short `key`, and the leading underscore is load-bearing: it marks the entry
 * as not-a-locale, so `spatie/laravel-translatable` and `SearchIndexObserver`
 * both step over it.
 *
 * ## The rule that matters is the key set
 *
 * *"`el[]` and `en[]` must contain the same set of `key` values — validated on
 * save."* Without it, a stop added in Greek and forgotten in English renders as
 * a gap in the English itinerary, and the `_geo` entry for it points at a stop
 * that half the guests never see. Nothing else in the shape can be wrong in a
 * way that is invisible; this one can.
 *
 * ## Refused rather than coerced
 *
 * A malformed itinerary is refused with a message, not silently repaired. This
 * column is written by the panel, by the API and by the WooCommerce importer,
 * and a "helpful" repair in one of those three is how the other two start
 * producing data nobody validated.
 */
final class ItineraryStopsShape implements ValidationRule
{
    private const MAX_KEY = 8;

    private const MAX_NAME = 120;

    private const MAX_DESCRIPTION = 500;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Null is "not configured", which hides the section. Perfectly valid.
        if ($value === null || $value === []) {
            return;
        }

        if (! is_array($value)) {
            $fail('catalog.product.validation.itinerary.shape')->translate();

            return;
        }

        $keysByLocale = [];

        foreach (LocaleResolver::installed() as $locale) {
            $stops = $value[$locale] ?? null;

            if ($stops === null) {
                $fail('catalog.product.validation.itinerary.missing_locale')->translate(['locale' => $locale]);

                return;
            }

            if (! is_array($stops)) {
                $fail('catalog.product.validation.itinerary.shape')->translate();

                return;
            }

            $keys = [];

            foreach ($stops as $stop) {
                $key = $this->validateStop($stop, $locale, $fail);

                if ($key === null) {
                    return;
                }

                $keys[] = $key;
            }

            $keysByLocale[$locale] = $keys;
        }

        $this->assertKeySetsMatch($keysByLocale, $fail);
        $this->assertGeoKeysAreKnown($value, $keysByLocale, $fail);
    }

    /** @return string|null the stop's key, or null when the stop is malformed */
    private function validateStop(mixed $stop, string $locale, Closure $fail): ?string
    {
        if (! is_array($stop)) {
            $fail('catalog.product.validation.itinerary.shape')->translate();

            return null;
        }

        $key = $stop['key'] ?? null;

        if (! is_string($key) || $key === '' || mb_strlen($key) > self::MAX_KEY) {
            $fail('catalog.product.validation.itinerary.key')->translate(['max' => self::MAX_KEY]);

            return null;
        }

        $name = $stop['name'] ?? null;

        if (! is_string($name) || trim($name) === '' || mb_strlen($name) > self::MAX_NAME) {
            $fail('catalog.product.validation.itinerary.name')->translate([
                'locale' => $locale,
                'max' => self::MAX_NAME,
            ]);

            return null;
        }

        $description = $stop['description'] ?? null;

        if ($description !== null && (! is_string($description) || mb_strlen($description) > self::MAX_DESCRIPTION)) {
            $fail('catalog.product.validation.itinerary.description')->translate(['max' => self::MAX_DESCRIPTION]);

            return null;
        }

        $duration = $stop['duration_minutes'] ?? null;

        if ($duration !== null && (! is_numeric($duration) || (int) $duration < 0)) {
            $fail('catalog.product.validation.itinerary.duration')->translate();

            return null;
        }

        return $key;
    }

    /**
     * §3.6's one real invariant.
     *
     * @param  array<string, list<string>>  $keysByLocale
     */
    private function assertKeySetsMatch(array $keysByLocale, Closure $fail): void
    {
        $locales = array_keys($keysByLocale);

        if (count($locales) < 2) {
            return;
        }

        $reference = $keysByLocale[$locales[0]];
        sort($reference);

        foreach (array_slice($locales, 1) as $locale) {
            $keys = $keysByLocale[$locale];
            sort($keys);

            if ($keys !== $reference) {
                $missing = array_merge(
                    array_diff($reference, $keys),
                    array_diff($keys, $reference),
                );

                $fail('catalog.product.validation.itinerary.key_mismatch')->translate([
                    'keys' => implode(', ', array_unique($missing)),
                ]);

                return;
            }
        }
    }

    /**
     * A `_geo` entry for a stop that does not exist is a coordinate nothing
     * renders — harmless today, and a stop somebody deleted while leaving its
     * pin on the map.
     *
     * @param  array<string, mixed>  $value
     * @param  array<string, list<string>>  $keysByLocale
     */
    private function assertGeoKeysAreKnown(array $value, array $keysByLocale, Closure $fail): void
    {
        $geo = $value['_geo'] ?? null;

        if (! is_array($geo)) {
            return;
        }

        $known = array_unique(array_merge(...array_values($keysByLocale) ?: [[]]));
        $unknown = array_diff(array_keys($geo), $known);

        if ($unknown !== []) {
            $fail('catalog.product.validation.itinerary.geo_unknown')->translate([
                'keys' => implode(', ', $unknown),
            ]);
        }
    }
}
