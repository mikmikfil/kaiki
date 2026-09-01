<?php

declare(strict_types=1);

use App\Enums\ApiKeyType;
use App\Enums\ApiScope;
use App\Enums\Role;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
 * Spec I18N-1, I18N-3.
 *
 * Greek is the primary locale — these operators run their business in Greek —
 * so an English string leaking through is a defect, not a cosmetic gap. The
 * failure mode is quiet: a hardcoded label looks perfectly fine in development
 * and is only noticed when an operator sends a screenshot.
 */

/**
 * Every dotted key in a lang array, flattened.
 *
 * Compared as a set rather than key-by-key at the top level, because a key
 * added three levels down and forgotten in the other file renders as the raw
 * dotted path on screen and nothing else goes wrong.
 *
 * @param  array<string, mixed>  $lines
 * @return list<string>
 */
function flattenKeys(array $lines, string $prefix = ''): array
{
    $keys = [];

    foreach ($lines as $key => $value) {
        $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

        if (is_array($value)) {
            $keys = [...$keys, ...flattenKeys($value, $path)];

            continue;
        }

        $keys[] = $path;
    }

    sort($keys);

    return $keys;
}

it('has identical key sets in both locales', function (): void {
    $en = flattenKeys(require lang_path('en/api_keys.php'));
    $el = flattenKeys(require lang_path('el/api_keys.php'));

    expect($el)->toBe($en);
})->group('fast');

it('translates every string in Greek, with no English left behind', function (): void {
    $en = require lang_path('en/api_keys.php');
    $el = require lang_path('el/api_keys.php');

    foreach (flattenKeys($en) as $key) {
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

    foreach (flattenKeys($en) as $key) {
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

    app()->setLocale('el');

    actingAs($owner)->get('/app/api-keys')
        ->assertSuccessful()
        ->assertSee(__('api_keys.model.plural'))
        ->assertSee('Κλειδιά API');
})->group('fast');

it('reuses the shared api vocabulary rather than duplicating it', function (): void {
    // The scope, type and environment labels belong to `api.php`, which the
    // public API error messages already use. Two copies of "Read trips" is how
    // the panel and the API start disagreeing about what a scope is called.
    $apiKeys = require lang_path('en/api_keys.php');

    expect(flattenKeys($apiKeys))->not->toContain('scope.products.read');

    foreach (['el', 'en'] as $locale) {
        app()->setLocale($locale);

        foreach (ApiScope::cases() as $scope) {
            expect($scope->label())->toBeString();
            expect($scope->label())->not->toBe("api.scope.{$scope->value}");
        }

        foreach (ApiKeyType::cases() as $type) {
            expect(__("api.key_type.{$type->value}"))->not->toBe("api.key_type.{$type->value}");
        }
    }
})->group('fast');
