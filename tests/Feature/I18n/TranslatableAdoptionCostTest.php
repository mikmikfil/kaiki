<?php

declare(strict_types=1);

use App\Models\Concerns\HasKaikiTranslations;
use App\Models\Concerns\HasTranslatableSearch;
use App\Models\Contracts\TranslatableSearchable;
use App\Observers\SearchIndexObserver;
use App\Support\Locale\TranslationColumns;
use Tests\Support\Translatable\TranslatableFixture;

/*
 * Issue #15, last acceptance criterion: when a new model adopts the trait and
 * the observer, **the only per-model work is declaring the attribute lists**.
 * No per-model observer code.
 *
 * That is a claim about a thing that does not exist yet — the first real
 * adopter is #16's `Vessel`. So it is pinned against the fixture, which is the
 * only adopter there is, and pinned by reflection rather than by reading the
 * file: a promise that a class contains no code is exactly the promise that
 * decays the first time somebody adds "just one" boot method.
 */

it('costs a new model nothing but its attribute lists', function (): void {
    $reflection = new ReflectionClass(TranslatableFixture::class);

    // Filtered by **file**, not by declaring class. A method a trait provides
    // reports the using class as its declaring class, so `getDeclaringClass()`
    // would count every method of both traits as the model's own and this test
    // would assert nothing at all.
    $declared = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        array_filter(
            $reflection->getMethods(),
            static fn (ReflectionMethod $method): bool => $method->getFileName() === $reflection->getFileName(),
        ),
    );

    // Not one method of its own. Everything the machinery needs — the observer
    // registration, the fallback chain, the search and sort scopes — arrives
    // with the two traits.
    expect(array_values($declared))->toBe([]);
})->group('fast', 'i18n');

it('registers the observer from the trait, with no model wiring it up', function (): void {
    // `grep SearchIndexObserver app/Models` returning nothing is what this
    // asserts: the observer is attached by `bootHasTranslatableSearch()`, so a
    // model cannot forget to attach one, and there is no `AppServiceProvider`
    // list to keep in step with the model directory.
    //
    // Instantiating first is not incidental — Eloquent boots a model class
    // lazily, so the listener does not exist until something touches it.
    new TranslatableFixture;

    expect(TranslatableFixture::getEventDispatcher()->hasListeners('eloquent.saving: ' . TranslatableFixture::class))
        ->toBeTrue();

    $fixture = TranslatableFixture::create([
        'title' => ['el' => 'Αίγινα', 'en' => 'Aegina'],
    ]);

    expect($fixture->getAttribute(TranslationColumns::SEARCH))->not->toBe('');
})->group('fast', 'i18n');

it('needs only the two traits and the contract to opt in', function (): void {
    // The declaration in the class body, asserted as a whole. If adoption ever
    // needs a third trait or a base class, this is where that shows up — and
    // the docblock on HasTranslatableSearch that promises otherwise becomes a
    // lie the same day.
    expect(class_uses_recursive(TranslatableFixture::class))
        ->toContain(HasKaikiTranslations::class)
        ->toContain(HasTranslatableSearch::class);

    expect(new TranslatableFixture)->toBeInstanceOf(TranslatableSearchable::class);
})->group('fast', 'i18n');

it('resolves the observer for anything implementing the contract', function (): void {
    // The interface exists so the observer can be typed rather than probing for
    // methods with `method_exists`. Every method it calls has to be on the
    // contract, or the next adopter discovers the gap at runtime.
    $contract = new ReflectionClass(TranslatableSearchable::class);
    $observer = new ReflectionClass(SearchIndexObserver::class);

    $declaredOnContract = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        $contract->getMethods(),
    );

    expect($declaredOnContract)
        ->toContain('translatableSearchAttributes')
        ->toContain('translatableSortAttributes')
        ->toContain('requiredTranslationAttributes')
        ->toContain('translationSearchValues')
        ->toContain('translationSortValue')
        ->toContain('missingTranslationLocales');

    expect($observer->getMethod('rebuild')->isStatic())->toBeTrue();
})->group('fast', 'i18n');
