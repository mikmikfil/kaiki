<?php

declare(strict_types=1);

namespace Tests\Support\Vat;

use Symfony\Component\Finder\Finder;
use Tests\Support\I18n\LiteralScanner;

/**
 * Finds VAT rates written into the source (spec CAT-11a).
 *
 * ADR-0002 put the rate in a reference table precisely so that no percentage
 * lives in code. The failure this guards is not sloppiness — it is that a
 * hardcoded `1300`, or a `match` from a percentage to a myDATA category,
 * silently overrides the table for whatever path touches it, and the operator's
 * invoice then disagrees with their product settings with nothing on screen to
 * explain it.
 *
 * ## Two shapes, because they fail differently
 *
 * **A rate literal** is a percentage stated in a VAT context — `vat_rate_bp =>
 * 1300`, `$vat = 0.24`, `'rate_bp' => 2400`. It is only a finding when it sits
 * next to a VAT word, because `1300` is also a perfectly ordinary number.
 *
 * **A category mapping** is worse and rarer: any construct that derives an AADE
 * `vatCategory` from a percentage. It centralises the tax code in PHP, which is
 * the one place a statutory change cannot reach without a deploy — and the
 * whole point of `vat_rates.vat_category` is that the mapping is data.
 *
 * ## It errs towards false negatives
 *
 * Same posture as {@see LiteralScanner}: a lint that fires
 * on innocent code earns an exemption, and after a dozen of those it enforces
 * nothing. The statutory percentages are matched as a fixed list rather than
 * "any number", so a `maxValue(10000)` on a form field is not a finding.
 */
final class VatRateScanner
{
    /**
     * Greek statutory VAT percentages, in basis points and as percents.
     *
     * These are the numbers that must never be written into code. Listing them
     * here is not the hardcoding CAT-11a forbids — this file is the detector,
     * and it never resolves a rate for anything. The reduced island rates are
     * included because they are the ones somebody would "helpfully" special-case.
     *
     * @var list<string>
     */
    private const STATUTORY = ['2400', '1300', '600', '1700', '900', '400', '24', '13', '17', '9', '6'];

    /**
     * Words that make a nearby number a VAT rate rather than a number.
     *
     * @var list<string>
     */
    private const VAT_WORDS = ['vat', 'φπα', 'rate_bp', 'vatcategory', 'vat_category', 'mydata'];

    /**
     * @param  list<string>  $paths  relative to the project root
     * @return list<array{file: string, line: int, snippet: string, why: string}>
     */
    public static function scan(array $paths): array
    {
        $findings = [];

        foreach ($paths as $path) {
            $absolute = self::root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);

            if (! is_dir($absolute)) {
                continue;
            }

            foreach (Finder::create()->files()->in($absolute)->name(['*.php', '*.blade.php']) as $file) {
                $relative = $path . '/' . str_replace('\\', '/', $file->getRelativePathname());

                if (self::isExempt($relative)) {
                    continue;
                }

                foreach (self::findingsIn((string) file_get_contents($file->getRealPath())) as $finding) {
                    $findings[] = ['file' => $relative] + $finding;
                }
            }
        }

        return $findings;
    }

    /**
     * @return list<array{line: int, snippet: string, why: string}>
     */
    public static function findingsIn(string $source): array
    {
        $findings = [];

        foreach (explode("\n", $source) as $index => $line) {
            $lower = mb_strtolower($line);

            // A comment explaining the rule is not a breach of it. Docblocks all
            // over this project say "1300 = 13.00%" while describing the column,
            // and flagging those would make the lint unusable.
            $trimmed = ltrim($lower);

            if ($trimmed === '' || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#')) {
                continue;
            }

            $mapping = self::mappingReason($lower);

            if ($mapping !== null) {
                $findings[] = ['line' => $index + 1, 'snippet' => trim($line), 'why' => $mapping];

                continue;
            }

            if (! self::mentionsVat($lower)) {
                continue;
            }

            foreach (self::STATUTORY as $value) {
                // Word-bounded, so 1300 does not match inside 13000, and the
                // decimal form `0.24` is caught by the second alternative.
                if (preg_match('/(?<![\d.])' . preg_quote($value, '/') . '(?![\d.])|0\.' . preg_quote($value, '/') . '\b/u', $lower) === 1) {
                    $findings[] = [
                        'line' => $index + 1,
                        'snippet' => trim($line),
                        'why' => "a statutory VAT percentage ({$value}) beside a VAT word",
                    ];

                    break;
                }
            }
        }

        return $findings;
    }

    private static function mentionsVat(string $lowerLine): bool
    {
        foreach (self::VAT_WORDS as $word) {
            if (str_contains($lowerLine, $word)) {
                return true;
            }
        }

        return false;
    }

    /** A percent-to-category mapping, in any of the shapes PHP offers. */
    private static function mappingReason(string $lowerLine): ?string
    {
        $mentionsCategory = str_contains($lowerLine, 'vatcategory') || str_contains($lowerLine, 'vat_category');

        if (! $mentionsCategory) {
            return null;
        }

        // `1300 => '2'`, `case 2400 =>`, `[2400 => ...]` — a number on the left
        // of an arrow beside the word category is a mapping, whatever the
        // surrounding construct is.
        foreach (self::STATUTORY as $value) {
            if (preg_match('/(?<![\d.])' . preg_quote($value, '/') . '(?![\d.])\s*=>/u', $lowerLine) === 1) {
                return 'a percentage mapped to a myDATA vatCategory in PHP';
            }
        }

        return null;
    }

    /**
     * Files that legitimately name the statutory numbers.
     *
     * Only this scanner and its fixture. Neither resolves a rate for anything;
     * they exist to detect the shapes. An exemption anywhere else is a request
     * to put the tax code back into PHP.
     *
     * @var list<string>
     */
    private const EXEMPT = [
        'tests/Support/Vat/VatRateScanner.php',
        'tests/Support/Vat/Fixtures/HardcodedVatRates.php',
    ];

    private static function isExempt(string $relative): bool
    {
        return in_array($relative, self::EXEMPT, true);
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
