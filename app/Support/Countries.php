<?php

declare(strict_types=1);

namespace App\Support;

use Collator;
use ResourceBundle;

/**
 * The countries a passenger's nationality is chosen from, as ISO 3166-1 alpha-2.
 *
 * ## Why a list and not a text box
 *
 * `booking_guests.nationality` is `char(2)` (§2.4), because the manifest the
 * Λιμεναρχείο reads wants a country, not whatever a guest types. The checkout
 * asked with a free text box, so «Greek» or «Ελληνική» reached a two-character
 * column: SQLite stored it anyway and MySQL refused the insert with a 500 —
 * which is how CI on MySQL found it before a guest on the live site did.
 *
 * ## Where the names come from
 *
 * ICU's own region table, through the `intl` extension every environment
 * already runs (ENV-2), so the names are in the guest's language with no
 * package and no list of 250 countries to keep in step by hand. Codes that
 * are not countries — `EU`, `UN`, `ZZ` and the like — are left out.
 */
final class Countries
{
    /** ICU region codes that are groupings or placeholders, not countries. */
    private const NOT_COUNTRIES = ['EU', 'EZ', 'UN', 'ZZ', 'QO', 'XA', 'XB'];

    /** @var array<string, array<string, string>> */
    private static array $cache = [];

    /**
     * `code => name`, sorted by name in the given language.
     *
     * @return array<string, string>
     */
    public static function options(string $locale): array
    {
        if (isset(self::$cache[$locale])) {
            return self::$cache[$locale];
        }

        $options = [];
        $bundle = ResourceBundle::create($locale, 'ICUDATA-region');
        $table = $bundle instanceof ResourceBundle ? $bundle->get('Countries') : null;

        if ($table instanceof ResourceBundle) {
            foreach ($table as $code => $name) {
                if (is_string($code) && preg_match('/^[A-Z]{2}$/', $code) === 1
                    && ! in_array($code, self::NOT_COUNTRIES, true) && is_string($name)) {
                    $options[$code] = $name;
                }
            }
        }

        $collator = new Collator($locale);
        uasort($options, static fn (string $a, string $b): int => (int) $collator->compare($a, $b));

        return self::$cache[$locale] = $options;
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::options('en'));
    }

    /** The code for a stored or submitted value, or null when it is not one. */
    public static function normalise(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $code = strtoupper(trim($value));

        return in_array($code, self::codes(), true) ? $code : null;
    }
}
