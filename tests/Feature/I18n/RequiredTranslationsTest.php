<?php

declare(strict_types=1);

use App\Exceptions\MissingTranslationException;
use App\Observers\SearchIndexObserver;
use App\Rules\TranslatableRequired;
use Illuminate\Support\Facades\Validator;
use Tests\Support\Translatable\TranslatableFixture;

/*
 * `docs/data-model.md` §1.6: "Both keys are required on write; a model observer
 * rejects a translation set missing `el` or `en`."
 *
 * Two enforcements of one rule, and both are needed. The observer answers for
 * imports and the API, where there is no form to put an error on. The rule
 * answers for a person, where a 500 page after twenty minutes of typing loses
 * the twenty minutes.
 *
 * What is being prevented is not an empty screen — the I18N-5 fallback means a
 * product with no English title renders its Greek one and looks *almost*
 * right. Nobody reports it. It is found months later when an English-speaking
 * guest asks what a καΐκι is.
 */

it('refuses to save a required attribute that is missing a locale', function (): void {
    TranslatableFixture::create(['title' => ['el' => 'Αίγινα']]);
})->throws(MissingTranslationException::class)->group('fast', 'i18n');

it('names the model, the attribute and the locale in the exception', function (): void {
    // The caller is an import or a queued job, so the message is the whole
    // diagnosis: a stack trace pointing at an observer says nothing about which
    // of eight thousand rows was wrong.
    expect(fn () => TranslatableFixture::create(['title' => ['en' => 'Aegina']]))
        ->toThrow(
            MissingTranslationException::class,
            '[Tests\Support\Translatable\TranslatableFixture::$title] has no translation for [el]',
        );
})->group('fast', 'i18n');

it('treats a whitespace-only translation as missing', function (): void {
    // The operator tabbed past the field. `getTranslations()` would report the
    // key as present, and the product would ship with a title made of spaces.
    TranslatableFixture::create(['title' => ['el' => 'Αίγινα', 'en' => '   ']]);
})->throws(MissingTranslationException::class)->group('fast', 'i18n');

it('refuses an update that removes a required locale', function (): void {
    $fixture = TranslatableFixture::create([
        'title' => ['el' => 'Αίγινα', 'en' => 'Aegina'],
    ]);

    // A row can be created correctly and edited into the same broken state, so
    // checking on insert only would leave the requirement half-enforced.
    $fixture->forgetTranslation('title', 'en');

    expect(fn () => $fixture->save())->toThrow(MissingTranslationException::class);
})->group('fast', 'i18n');

it('does not require a locale for an attribute that is only searchable', function (): void {
    // `summary` is indexed and never required. Enforcing every translatable
    // column would mean an operator could not save a draft product until they
    // had written its description twice.
    $fixture = TranslatableFixture::create([
        'title' => ['el' => 'Αίγινα', 'en' => 'Aegina'],
        'summary' => ['el' => 'Μια μέρα στον Σαρωνικό'],
    ]);

    expect($fixture->exists)->toBeTrue();
})->group('fast', 'i18n');

it('reports every missing locale of every required attribute', function (): void {
    $fixture = new TranslatableFixture;
    $fixture->setTranslations('title', []);

    expect(SearchIndexObserver::missingTranslations($fixture))->toBe(['title' => ['el', 'en']]);
})->group('fast', 'i18n');

it('passes validation when every required locale is filled in', function (): void {
    $validator = Validator::make(
        ['title' => ['el' => 'Αίγινα', 'en' => 'Aegina']],
        ['title' => [new TranslatableRequired]],
    );

    expect($validator->passes())->toBeTrue();
})->group('fast', 'i18n');

it('fails validation naming the missing language, not its code', function (): void {
    $validator = Validator::make(
        ['title' => ['el' => 'Αίγινα']],
        ['title' => [new TranslatableRequired]],
    );

    // "English", not "en". The operator picked their language from a switcher
    // that said Ελληνικά; answering in ISO codes asks them to learn our
    // vocabulary to fix their own typo.
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('title'))->toContain('English')
        ->and($validator->errors()->first('title'))->not->toContain('[en]');
})->group('fast', 'i18n');

it('gives the operator that message in their own language', function (): void {
    app()->setLocale('el');

    $validator = Validator::make(
        ['title' => ['en' => 'Aegina']],
        ['title' => [new TranslatableRequired]],
    );

    // I18N-1: the message itself comes from `lang/el/validation.php`, and both
    // files carry the key — the I18N-3 parity gate would fail if only one did.
    expect($validator->errors()->first('title'))
        ->toContain('πρέπει να συμπληρωθεί')
        ->toContain('Ελληνικά');
})->group('fast', 'i18n');

it('treats a blank array translation as not filled in', function (): void {
    // `["", ""]` in a translatable array column (`includes`, data-model §3.5).
    // Every individual component behaves correctly here and the result is an
    // empty bulleted list on the hosted page with no warning anywhere.
    $validator = Validator::make(
        ['includes' => ['el' => ['Γεύμα'], 'en' => ['', '  ']]],
        ['includes' => [new TranslatableRequired]],
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('includes'))->toContain('English');
})->group('fast', 'i18n');

it('reports every required locale when the value is not translatable at all', function (): void {
    // Filament hands a translatable field through as an array; a plain string
    // arriving here means the form was built without the translatable
    // component. "Fill in Greek and English" points at that better than "must
    // be an array" does.
    $rule = new TranslatableRequired;

    expect($rule->missingLocales('Aegina'))->toBe(['el', 'en'])
        ->and($rule->missingLocales(null))->toBe(['el', 'en']);
})->group('fast', 'i18n');

it('can be asked about a narrower set of locales than the default', function (): void {
    $rule = new TranslatableRequired(['en']);

    expect($rule->missingLocales(['el' => 'Αίγινα']))->toBe(['en'])
        ->and($rule->missingLocales(['en' => 'Aegina']))->toBe([]);
})->group('fast', 'i18n');

it('reads its locale list from config, not from the installed locales', function (): void {
    // EXT-7 says adding a locale is a lang-file change only. If this list were
    // derived from `app.available_locales`, shipping German lang files would
    // invalidate every product in every catalogue overnight and lock operators
    // out of their own data.
    config()->set('kaiki.i18n.required_locales', ['el']);

    $fixture = TranslatableFixture::create(['title' => ['el' => 'Αίγινα']]);

    expect($fixture->exists)->toBeTrue()
        ->and(config('app.available_locales'))->toContain('en');
})->group('fast', 'i18n');
