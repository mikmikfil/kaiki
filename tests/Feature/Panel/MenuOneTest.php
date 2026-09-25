<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Filament\App\Resources\CancellationPolicyResource;
use App\Filament\App\Resources\EnquiryResource;
use App\Filament\App\Resources\QuoteResource;
use App\Filament\App\Resources\RatePlanResource;
use App\Filament\App\Resources\SeasonResource;
use App\Filament\App\Resources\VesselBlockResource;
use App\Filament\App\Resources\VesselResource;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| Menu 1 (product owner, 2026-09-16)
|--------------------------------------------------------------------------
|
| Four short groups and 13 entries. The screens that left the sidebar are
| tabs on a neighbour's list now, so each of them has to stay reachable: from
| that tab bar, at its old address.
|
*/

it('reads as the four groups, in order, with the short labels', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    $html = (string) actingAs($owner)->get('/app?lang=el')->assertSuccessful()->getContent();

    expect($html)->toContain('Αρχική');

    $positions = array_map(
        static fn (string $label): int|false => strpos($html, e($label)),
        ['Σήμερα', 'Πωλήσεις', 'Κατάλογος', 'Στόλος'],
    );

    expect($positions)->not->toContain(false)
        ->and($positions)->toBe(array_values(collect($positions)->sort()->all()));

    foreach (['Τιμές', 'Λιμάνια', 'Στατιστικά'] as $label) {
        expect($html)->toContain($label);
    }

    // «Περίοδοι» and «Πολιτικές ακύρωσης» came **back** to the sidebar on
    // 2026-09-22, reversing their two lines of Menu 1: each is set up once,
    // applies to every trip, and is looked for by name months later, which is
    // not the same kind of screen as the two below. Their tab bar went with
    // them, so «Τιμοκατάλογοι» now carries no tabs at all.
    foreach (['Περίοδοι', 'Πολιτικές ακύρωσης'] as $back) {
        expect(preg_match('/fi-sidebar-item-label[^>]*>\s*' . preg_quote(e($back), '/') . '\s*</u', $html))
            ->toBe(1, "{$back} is not in the sidebar");
    }

    foreach (['Ερωτήματα', 'Δεσμεύσεις σκάφους', 'Λιμάνια και σημεία συνάντησης'] as $gone) {
        expect(preg_match('/fi-sidebar-item-label[^>]*>\s*' . preg_quote(e($gone), '/') . '\s*</u', $html))->toBe(0, "{$gone} is still in the sidebar");
    }
})->group('fast');

it('reaches the screens that left the sidebar through tabs on their neighbour', function (string $resource, string $neighbour): void {
    $owner = OperatorUser::withRole(Role::Owner);

    actingAs($owner)->get($resource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee('ka-sibling-tabs', escape: false)
        ->assertSee($neighbour::getUrl('index'), escape: false);
})->with([
    'ερωτήματα, from προσφορές' => [EnquiryResource::class, QuoteResource::class],
    'δεσμεύσεις, from σκάφη' => [VesselBlockResource::class, VesselResource::class],
])->group('fast');

it('leaves the price lists without a tab bar, now that their siblings have menu entries', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    // The addresses did not move — only the way in. A bookmark, the manual and
    // the testing guide all still land here.
    foreach ([RatePlanResource::class, SeasonResource::class, CancellationPolicyResource::class] as $resource) {
        actingAs($owner)->get($resource::getUrl('index'))
            ->assertSuccessful()
            ->assertDontSee('ka-sibling-tabs', escape: false);
    }
})->group('fast');

it('keeps the tab bar off the forms', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    actingAs($owner)->get(SeasonResource::getUrl('create'))
        ->assertSuccessful()
        ->assertDontSee('ka-sibling-tabs', escape: false);
})->group('fast');
