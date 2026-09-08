<?php

declare(strict_types=1);

use App\Models\Tenant;

/*
|--------------------------------------------------------------------------
| «ΔΟΥ: ΔΟΥ Πειραιά»
|--------------------------------------------------------------------------
|
| The hosted footer printed the word twice. Four templates render `tax_office`:
| two added a «ΔΟΥ» label of their own, and two — the invoice and the
| ναυλοσύμφωνο — printed the bare value, which read correctly only because the
| operator happened to type the prefix in.
|
| Neither convention was safe alone, so the value carries the prefix and the
| labels came off.
|
*/

it('adds the prefix when the operator typed only the town', function (): void {
    $tenant = new Tenant(['tax_office' => 'Πειραιά']);

    expect($tenant->taxOfficeName())->toBe('ΔΟΥ Πειραιά');
})->group('fast');

it('does not add it twice when the operator copied it from their paperwork', function (): void {
    // The bug, as it appeared: «ΔΟΥ: ΔΟΥ Πειραιά».
    $tenant = new Tenant(['tax_office' => 'ΔΟΥ Πειραιά']);

    expect($tenant->taxOfficeName())->toBe('ΔΟΥ Πειραιά');
})->group('fast');

it('leaves an office whose name has the word in the middle alone', function (): void {
    // «Α΄ ΔΟΥ Θεσσαλονίκης» is a real office. A `str_starts_with` check would
    // have prefixed it a second time.
    $tenant = new Tenant(['tax_office' => 'Α΄ ΔΟΥ Θεσσαλονίκης']);

    expect($tenant->taxOfficeName())->toBe('Α΄ ΔΟΥ Θεσσαλονίκης');
})->group('fast');

it('answers null rather than a bare prefix when nothing was entered', function (): void {
    // «ΔΟΥ» on its own in a footer is worse than an absent line.
    foreach ([null, '', '   '] as $value) {
        expect((new Tenant(['tax_office' => $value]))->taxOfficeName())->toBeNull();
    }
})->group('fast');
