<?php

declare(strict_types=1);

use App\Enums\Concerns\HasTranslatedLabel;
use App\Support\Locale\LocaleResolver;

/*
 * Spec CNV-11: an enum label that reaches an operator comes from a lang file,
 * never from a literal in the enum.
 *
 * `NoHardcodedStringsTest` enforces the negative — no literal in the class.
 * Nothing enforced the positive until now, and the gap is the one that actually
 * ships: a new M1 enum with no block in `enums.php` passes Pint, PHPStan, the
 * parity check and the literal scanner, and renders
 * `enums.departure_status.boarding.label` on screen. M1 adds a dozen enums.
 *
 * This test discovers them by reflection rather than by a maintained list,
 * because a maintained list is one more thing to forget.
 */

/**
 * Every enum in `app/Enums` that uses the shared label trait.
 *
 * @return list<class-string>
 */
function translatedEnums(): array
{
    $enums = [];

    foreach (glob(app_path('Enums/*.php')) ?: [] as $path) {
        $class = 'App\\Enums\\' . basename($path, '.php');

        if (! enum_exists($class)) {
            continue;
        }

        if (in_array(HasTranslatedLabel::class, class_uses_recursive($class), true)) {
            $enums[] = $class;
        }
    }

    return $enums;
}

it('finds the enums, so this test cannot pass by discovering nothing', function (): void {
    // A reflective test that silently matches zero classes is green forever.
    expect(translatedEnums())->not->toBeEmpty()
        ->and(count(translatedEnums()))->toBeGreaterThanOrEqual(6);
})->group('fast', 'i18n');

it('resolves a real label for every case of every enum, in every locale', function (): void {
    $missing = [];

    foreach (LocaleResolver::installed() as $locale) {
        app()->setLocale($locale);

        foreach (translatedEnums() as $enum) {
            foreach ($enum::cases() as $case) {
                $label = $case->label();

                // The trait returns the dotted key when the line is absent,
                // which is exactly the tell — I18N-1 relies on a missing string
                // looking missing rather than looking like a lowercase word.
                if (str_starts_with($label, 'enums.')) {
                    $missing[] = "{$locale}: {$label}";
                }
            }
        }
    }

    expect($missing)->toBe([], "enum labels with no lang line:\n" . implode("\n", $missing));
})->group('fast', 'i18n');

it('gives every enum case a distinct label within its own enum', function (): void {
    // Two cases sharing a label is a copy-paste in the lang file, and it makes
    // a select box where two options read identically — the operator picks one
    // at random and the wrong record is saved.
    foreach (LocaleResolver::installed() as $locale) {
        app()->setLocale($locale);

        foreach (translatedEnums() as $enum) {
            $labels = array_map(static fn (object $case): string => $case->label(), $enum::cases());

            expect(array_unique($labels))->toHaveCount(
                count($labels),
                "{$enum} has duplicate labels in {$locale}: " . implode(', ', $labels),
            );
        }
    }
})->group('fast', 'i18n');

it('has no orphaned enum block in the lang files', function (): void {
    // The other direction: a block left behind after an enum is deleted or
    // renamed. Harmless at runtime, which is why it survives for years.
    app()->setLocale('en');

    /** @var array<string, mixed> $blocks */
    $blocks = (array) trans('enums');

    $namespaces = array_map(
        static fn (string $enum): string => str_replace('enums.', '', $enum::translationNamespace()),
        translatedEnums(),
    );

    // `locale` is metadata for the language switcher rather than a backed enum;
    // it lives here because it is the same kind of label.
    $expected = [...$namespaces, 'locale'];

    expect(array_diff(array_keys($blocks), $expected))->toBe([]);
})->group('fast', 'i18n');
