<?php

declare(strict_types=1);

use App\Enums\ApiKeyType;
use App\Enums\ApiScope;
use App\Enums\Role;

use function Pest\Laravel\actingAs;

use Tests\Support\I18n\LangFiles;
use Tests\Support\OperatorUser;

/*
 * Spec I18N-1, I18N-3.
 *
 * Greek is the primary locale — these operators run their business in Greek —
 * so an English string leaking through is a defect, not a cosmetic gap. The
 * failure mode is quiet: a hardcoded label looks perfectly fine in development
 * and is only noticed when an operator sends a screenshot.
 */

it('has identical key sets in both locales', function (): void {
    $en = LangFiles::flatten(require lang_path('en/api_keys.php'));
    $el = LangFiles::flatten(require lang_path('el/api_keys.php'));

    expect($el)->toBe($en);
})->group('fast');

it('translates every string in Greek, with no English left behind', function (): void {
    $en = require lang_path('en/api_keys.php');
    $el = require lang_path('el/api_keys.php');

    foreach (LangFiles::flatten($en) as $key) {
        $english = data_get($en, $key);
        $greek = data_get($el, $key);

        expect($greek)->toBeString();
        expect($greek)->not->toBe('');

        // A Greek file that still holds the English sentence is the failure
        // this catches — a copied file with half the work done.
        if (is_string($english) && ! str_contains($english, ':')) {
            expect($greek)->not->toBe($english, "api_keys.{$key} is still English in lang/el");
        }
    }
})->group('fast');

it('keeps every placeholder that the English string uses', function (): void {
    // A dropped `:name` renders the literal word, and the operator sees
    // "Revoke ":name"?" in a confirmation dialog about deleting their access.
    $en = require lang_path('en/api_keys.php');
    $el = require lang_path('el/api_keys.php');

    foreach (LangFiles::flatten($en) as $key) {
        $english = (string) data_get($en, $key);
        $greek = (string) data_get($el, $key);

        preg_match_all('/:([a-z_]+)/', $english, $placeholders);

        foreach ($placeholders[1] as $placeholder) {
            expect($greek)->toContain(":{$placeholder}");
        }
    }
})->group('fast');

it('renders the page in Greek for an owner', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    // Drives the real path rather than `app()->setLocale('el')`. Before #12 no
    // middleware set the locale, so presetting it by hand was the only way to
    // reach this assertion — and it proved only that Blade reads the locale,
    // never that a request arrives with the right one.
    actingAs($owner)->get('/app/api-keys?lang=el')
        ->assertSuccessful()
        ->assertSee(__('api_keys.model.plural'))
        ->assertSee('Κλειδιά API');
})->group('fast');

it('reuses the shared enum vocabulary rather than duplicating it', function (): void {
    // The scope, type and environment labels belong to the enums, and the enums
    // read `enums.php` (CNV-11). Two copies of "Read trips" is how the panel and
    // the API start disagreeing about what a scope is called.
    //
    // They lived in `api.php` until #12 consolidated every enum label into one
    // file; the claim being tested is unchanged — one home, no duplication, no
    // literal — only the address moved. `api.php` now holds the API error
    // envelope alone, which is prose for an integrator rather than a form label.
    $apiKeys = require lang_path('en/api_keys.php');

    expect(LangFiles::flatten($apiKeys))->not->toContain('scope.products.read');

    foreach (['el', 'en'] as $locale) {
        app()->setLocale($locale);

        foreach (ApiScope::cases() as $scope) {
            expect($scope->label())->toBeString();
            expect($scope->label())->not->toBe("enums.api_scope.{$scope->value}.label");
        }

        foreach (ApiKeyType::cases() as $type) {
            expect($type->label())->not->toBe("enums.api_key_type.{$type->value}.label");
        }
    }
})->group('fast');
