<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Support;

/**
 * Is this ΑΦΜ real enough to put on a ΤΠΥ? (spec MYD-3.5, MYD-8.)
 *
 * ## What this can and cannot tell you
 *
 * It answers *"is this a well-formed number"*, not *"does this business exist"*.
 * The Greek ΑΦΜ carries a modulus-11 check digit, so a mistyped digit is caught
 * here for free and without a network call; whether the number belongs to a
 * trading company is a question only AADE can answer, and asking it needs the
 * credentials MYD-1 stores.
 *
 * That distinction is the whole reason this class exists separately from the
 * client. MYD-3.5 makes validation a **precondition** of issuing a ΤΠΥ, and a
 * precondition that needs a network call is a precondition that fails when the
 * network does — on a Saturday in August, on the confirmation page, in front of
 * a guest. A checksum catches the overwhelmingly common error, which is a typo.
 *
 * ## A bad number produces a receipt, not an error
 *
 * MYD-3.5 again: *"An ΑΦΜ that fails validation does not produce a ΤΠΥ; it
 * produces an ΑΛΠ and an operator-visible warning."* The guest is not stopped
 * and the sale is not held up; the operator is told, and can fix it into a
 * credit note and a re-issue if the customer really did need the invoice. The
 * alternative is a document AADE rejects days later, which is worse for
 * everybody and is discovered by the wrong person.
 *
 * ## Foreign customers
 *
 * MYD-8 uses ISO country codes. A number from outside Greece is not checked
 * arithmetically here — every EU member state has its own scheme and
 * reimplementing twenty-six of them from memory is how a valid Italian VAT
 * number gets refused. Non-Greek numbers are accepted on shape alone, which is
 * the honest limit of what this can know without VIES.
 */
final class VatNumber
{
    /** Greece, in ISO 3166-1 alpha-2. */
    public const GREECE = 'GR';

    /**
     * Strip the noise people type: spaces, dots, dashes, and a country prefix.
     *
     * «EL 099 999 999», «GR099999999» and «099999999» are the same number
     * written by three people, and a form that accepts only the third is a form
     * that tells a paying customer their own ΑΦΜ is wrong.
     *
     * **«EL» is Greece's VAT prefix and «GR» is its country code**, and both
     * turn up — the first on invoices, the second in address forms.
     *
     * Any leading pair of letters is stripped, not only those two, because an
     * Italian customer writes «IT12345678901» and a German writes
     * «DE123456789». The cost of being general is that a national scheme whose
     * numbers genuinely begin with two letters loses them here; the benefit is
     * that twenty-six prefixes work without twenty-six special cases. This is a
     * shape check, and the trade is worth it in that direction.
     */
    public static function normalise(?string $raw): string
    {
        $value = strtoupper(trim((string) $raw));
        $value = (string) preg_replace('/[\s.\-\/]/u', '', $value);

        // Only when something is left: «EL» alone normalises to «EL», not to an
        // empty string that a caller might read as "no number given".
        return (string) preg_replace('/^([A-Z]{2})(?=.+)/', '', $value);
    }

    /**
     * Is this a well-formed number for the given country?
     *
     * The country decides the rule: Greece gets the checksum, everywhere else
     * gets a shape check, for the reason in the class docblock.
     */
    public static function isValid(?string $raw, string $country = self::GREECE): bool
    {
        $value = self::normalise($raw);

        if ($value === '') {
            return false;
        }

        return strtoupper($country) === self::GREECE
            ? self::isValidGreek($value)
            : self::isPlausibleForeign($value);
    }

    /**
     * The Greek modulus-11 check, on a normalised nine-digit string.
     *
     * The first eight digits are weighted by descending powers of two — 256,
     * 128, 64, 32, 16, 8, 4, 2 — summed, taken modulo 11, and modulo 10 again.
     * That last step is what makes a remainder of 10 into a check digit of 0,
     * and leaving it out is the classic way to reject one number in eleven.
     *
     * `000000000` fails: it satisfies the arithmetic and is not an ΑΦΜ. It is
     * also exactly what an empty form field becomes after normalisation in a
     * system that pads, so refusing it explicitly is worth the line.
     */
    public static function isValidGreek(string $value): bool
    {
        if (preg_match('/^\d{9}$/', $value) !== 1) {
            return false;
        }

        if ($value === '000000000') {
            return false;
        }

        $sum = 0;

        for ($position = 0; $position < 8; $position++) {
            $sum += (int) $value[$position] * (2 ** (8 - $position));
        }

        return $sum % 11 % 10 === (int) $value[8];
    }

    /**
     * A shape check for a number issued outside Greece.
     *
     * Two to twelve alphanumerics, which is the range EU VAT numbers occupy.
     * Deliberately permissive: see the class docblock on why guessing at
     * twenty-six national schemes is worse than accepting a shape.
     */
    private static function isPlausibleForeign(string $value): bool
    {
        return preg_match('/^[A-Z0-9]{2,12}$/', $value) === 1;
    }
}
