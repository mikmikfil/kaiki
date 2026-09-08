<?php

declare(strict_types=1);

use App\Filament\App\Resources\ProductResource;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The trip's public address, generated but never taken over
|--------------------------------------------------------------------------
|
| Asked for by the product owner: the field should be called what it is, be
| filled in automatically from the Greek title in greeklish, and still be
| editable.
|
| The third of those is the one with teeth. A slug is a public address: once a
| page has been shared, put in an email or indexed, changing it breaks the link.
| So "generated" has to mean *generated into an empty field*, and never
| regenerated because somebody fixed a typo in the title six weeks later.
|
*/

it('turns a Greek title into greeklish', function (string $title, string $expected): void {
    // Laravel's own Greek transliteration map rather than a hand-written table:
    // a table of ours would be one more thing to get wrong for ξ, ψ and the
    // accented vowels, and would drift from the framework's.
    expect(Str::slug($title, '-', 'el'))->toBe($expected);
})->with([
    ['Ηλιοβασίλεμα στην Αίγινα', 'hliovasilema-stin-aighina'],
    ['Ιδιωτική ημέρα με σκάφος', 'idiotiki-imera-me-skafos'],
    ['Ολοήμερη στα τρία νησιά', 'oloimeri-sta-tria-nisia'],
]);

it('fills an empty slug from the title', function (): void {
    expect(ProductResource::slugFor('', 'Ηλιοβασίλεμα στην Αίγινα'))->toBe('hliovasilema-stin-aighina')
        ->and(ProductResource::slugFor(null, 'Ηλιοβασίλεμα στην Αίγινα'))->toBe('hliovasilema-stin-aighina');
});

it('never overwrites a slug somebody has already chosen', function (): void {
    // The whole point. An operator editing a trip that has been live all season
    // must not have its address rewritten because they corrected the title.
    expect(ProductResource::slugFor('sunset-aegina', 'Ηλιοβασίλεμα στην Αίγινα'))->toBeNull();
});

it('does nothing for an empty title', function (): void {
    // Clearing the title to retype it must not blank the address on the way.
    expect(ProductResource::slugFor('', '   '))->toBeNull()
        ->and(ProductResource::slugFor('', null))->toBeNull();
});
