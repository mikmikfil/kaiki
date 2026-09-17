<?php

declare(strict_types=1);

use App\Enums\Role;
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

    foreach (['Ερωτήματα', 'Περίοδοι', 'Πολιτικές ακύρωσης', 'Δεσμεύσεις σκάφους', 'Λιμάνια και σημεία συνάντησης'] as $gone) {
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
    'περίοδοι' => [SeasonResource::class, RatePlanResource::class],
    'δεσμεύσεις, from σκάφη' => [VesselBlockResource::class, VesselResource::class],
])->group('fast');

it('keeps the tab bar off the forms', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    actingAs($owner)->get(SeasonResource::getUrl('create'))
        ->assertSuccessful()
        ->assertDontSee('ka-sibling-tabs', escape: false);
})->group('fast');
