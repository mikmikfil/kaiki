<?php

declare(strict_types=1);

use Tests\Support\Vat\VatRateScanner;

/*
|--------------------------------------------------------------------------
| No VAT rate in the source — spec CAT-11a, ADR-0002
|--------------------------------------------------------------------------
|
| ADR-0002 rejected a `vat_rate_bp` column with a `1300` default and chose a
| platform-owned reference table, on the reasoning that a rate written into the
| schema or the code is a rate this project decided — and brief §10 explicitly
| refuses to decide it.
|
| A hardcoded rate does not throw. It silently overrides the table for whatever
| path touches it, and the operator's invoice then disagrees with their own
| product settings with nothing on screen to explain why. This gate is how that
| stays impossible rather than merely discouraged.
|
*/

/** @return list<string> */
function vatScannedPaths(): array
{
    return [
        'app',
        'config',
        'database',
        // Lang files are scanned too: a "13%" written into a helper text reads
        // as authoritative to the operator even though no code resolves it.
        'lang',
        'routes',
        'resources/views',
    ];
}

it('has no VAT rate or category mapping anywhere in the source', function (): void {
    $findings = VatRateScanner::scan(vatScannedPaths());

    $report = array_map(
        static fn (array $f): string => "{$f['file']}:{$f['line']} — {$f['snippet']} ({$f['why']})",
        $findings,
    );

    expect($report)->toBe([], sprintf(
        "VAT rates written into the source:\n%s\n\n" .
        'The rate belongs in the `vat_rates` table and the myDATA category belongs in the row beside it ' .
        '(ADR-0002, spec CAT-11a). Nothing in PHP may state a percentage or map one to a vatCategory.',
        implode("\n", $report),
    ));
})->group('fast');

it('can actually detect every shape it claims to', function (): void {
    // Pointed at a fixture that is permanently wrong, so this test fails the day
    // the scanner stops working rather than the day somebody notices.
    $source = (string) file_get_contents(
        dirname(__DIR__) . '/Support/Vat/Fixtures/HardcodedVatRates.php',
    );

    $reasons = array_map(
        static fn (array $f): string => $f['why'],
        VatRateScanner::findingsIn($source),
    );

    expect($reasons)->not->toBeEmpty()
        ->and(implode(' ', $reasons))->toContain('1300')
        ->and(implode(' ', $reasons))->toContain('vatCategory');
})->group('fast');

it('does not fire on the shapes that are legitimate', function (): void {
    // The other half, and the half that decides whether anyone keeps the lint.
    // A form's maximum, an unrelated integer, and a docblock explaining the unit
    // are all ordinary code that a blunter scanner would flag.
    $innocent = <<<'PHP'
    <?php
    // 1300 basis points is 13.00% — explaining the unit, not setting a rate.
    class Ordinary
    {
        public function form(): array
        {
            return ['rate_bp' => ['max' => 10000, 'min' => 0]];
        }

        public function unrelated(): int
        {
            return 1300 + 24;
        }

        public function readsTheTable(string $category): string
        {
            return $category;
        }
    }
    PHP;

    expect(VatRateScanner::findingsIn($innocent))->toBe([]);
})->group('fast');

it('points at directories that exist, so it cannot pass by scanning nothing', function (): void {
    $missing = array_values(array_filter(
        vatScannedPaths(),
        static fn (string $path): bool => ! is_dir(base_path($path)),
    ));

    expect($missing)->toBe([]);
})->group('fast');
