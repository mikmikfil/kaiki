<?php

declare(strict_types=1);

use Tests\Support\I18n\AllowList;
use Tests\Support\I18n\LiteralScanner;

/*
 * Spec I18N-1 / I18N-2: no user-facing literal may appear in PHP or Blade, and
 * CI fails naming `file:line` when one does.
 *
 * The rule held through #6, #9 and #10 because three issues happened to be
 * careful. M1 adds twelve Filament resources; one of them will forget, and the
 * result renders perfectly in development and is only noticed when a Greek
 * operator sends a screenshot with an English word in it.
 *
 * The scanner errs towards false negatives on purpose — see LiteralScanner. A
 * lint that fires on a CSS class earns an allow-list entry rather than a fix,
 * and after a dozen of those it enforces nothing at all.
 */

/**
 * Directories where a user-facing string is a defect.
 *
 * @return list<string>
 */
function scannedPaths(): array
{
    return [
        'app/Filament',
        'app/Enums',
        'app/Http',
        'app/Domain',
        'app/Providers',
        'app/Models',
        'app/Policies',
        'app/Support',
        // Do not exist yet; the scanner skips a missing directory, and listing
        // them now means M2's mail and SMS templates are covered the day they
        // land rather than the day someone remembers (I18N-9).
        'app/Notifications',
        'app/Mail',
        'resources/views',
    ];
}

it('has no hardcoded user-facing string in any scanned source file', function (): void {
    $findings = LiteralScanner::scan(scannedPaths());

    $report = array_map(
        static fn (array $f): string => "{$f['file']}:{$f['line']} — \"{$f['literal']}\" ({$f['why']})",
        $findings,
    );

    expect($report)->toBe([], "hardcoded strings:\n" . implode("\n", $report));
})->group('fast', 'i18n');

it('can actually detect a hardcoded string', function (): void {
    // The issue's Notes are explicit: "A lint that has never failed is not a
    // lint." Rather than asking a human to sabotage the tree and remember to
    // revert, the scanner is pointed at a fixture that is permanently wrong.
    $literals = LiteralScanner::literals(['tests/Support/I18n/Fixtures']);

    expect($literals)
        ->toContain('Vessels')                       // ->label('Vessels')
        ->toContain('Save this trip')                // {{ 'Save this trip' }}
        ->toContain('Departures')                    // $navigationLabel
        ->toContain('No trips yet')                  // ->emptyStateHeading()
        ->toContain('Delete this vessel');           // a call Pint wrapped across lines
})->group('fast', 'i18n');

it('does not flag the things that are not strings for people', function (): void {
    // The other half of the claim. If this test can be made to pass by
    // loosening the scanner, the scanner is worthless; these are the exact
    // shapes that appear on every Filament resource in the codebase.
    $literals = LiteralScanner::literals(['tests/Support/I18n/Fixtures']);

    expect($literals)->not->toContain('heroicon-o-key');           // an icon name
    expect($literals)->not->toContain('created_at');               // a column
    expect($literals)->not->toContain('el');                       // a locale code
    expect($literals)->not->toContain('flex items-center gap-2');  // Tailwind classes
})->group('fast', 'i18n');

it('keeps the allow-list documented and honest', function (): void {
    // An allow-list without reasons becomes the place strings go to be
    // forgotten. Both lists are asserted, because the identical-translation one
    // is just as easy to pad.
    $entries = [...AllowList::literals(), ...AllowList::identicalTranslations()];

    foreach ($entries as $key => $reason) {
        expect($reason)->toBeString()
            ->and(mb_strlen($reason))->toBeGreaterThan(15, "allow-list entry `{$key}` needs a real reason");
    }
})->group('fast', 'i18n');
