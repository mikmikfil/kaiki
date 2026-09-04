<?php

declare(strict_types=1);

namespace Tests\Support\Vat\Fixtures;

/**
 * Permanently wrong, on purpose (spec CAT-11a, TST-8).
 *
 * "A lint that has never failed is not a lint." Rather than asking somebody to
 * sabotage the tree and remember to revert, the scanner is pointed at this file
 * and asserted to find every shape in it. It is excluded from the real scan by
 * name, is autoloaded only in tests, and resolves a rate for nothing.
 *
 * Each method below is a way a well-meaning developer puts the Greek tax code
 * back into PHP.
 */
final class HardcodedVatRates
{
    /** The obvious one: a rate literal in a VAT context. */
    public function defaultRate(): int
    {
        $vat_rate_bp = 1300;

        return $vat_rate_bp;
    }

    /** The same thing wearing a decimal. */
    public function vatMultiplier(): float
    {
        return 0.24;
    }

    /** The dangerous one: the percent-to-category mapping ADR-0002 exists to prevent. */
    public function vatCategoryFor(int $rateBp): string
    {
        return match ($rateBp) {
            2400 => '1',
            1300 => '2',
            600 => '3',
            default => '8',
        };
    }

    /**
     * An array is a match statement with extra steps.
     *
     * @return array<string, array<int, string>>
     */
    public function vatCategoryTable(): array
    {
        return ['vat_category' => [2400 => '1', 1300 => '2']];
    }
}
