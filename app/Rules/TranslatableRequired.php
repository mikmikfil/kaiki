<?php

declare(strict_types=1);

namespace App\Rules;

use App\Exceptions\MissingTranslationException;
use App\Observers\SearchIndexObserver;
use App\Support\Locale\LocaleResolver;
use App\Support\Locale\TranslationValue;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A translatable field must be filled in for every required locale.
 *
 * `docs/data-model.md` §1.6 requires both `el` and `en` on every translatable
 * column, and {@see SearchIndexObserver} enforces that on save by throwing
 * {@see MissingTranslationException}. This rule is the same requirement stated
 * where an operator can act on it: beside the field, in their own language,
 * before the form is submitted.
 *
 * Both are needed. An exception is the right answer for an import or an API
 * caller and the wrong one for a person — a 500 page after twenty minutes of
 * typing loses the twenty minutes. A validation rule is the right answer for a
 * person and no answer at all for the CSV importer, which never renders a form.
 *
 * ## What counts as filled in
 *
 * {@see TranslationValue::isBlank()} decides, so this rule, the fallback chain
 * and the search index all agree. A whitespace-only value is blank, and so is
 * `["", ""]` in a translatable array column — an operator who tabbed through
 * the English inclusions list has not translated the product, and the whole
 * point of this rule is to say so while they are still looking at it.
 */
final class TranslatableRequired implements ValidationRule
{
    /**
     * @param  list<string>|null  $locales  defaults to the required locales
     */
    public function __construct(private readonly ?array $locales = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $missing = $this->missingLocales($value);

        if ($missing === []) {
            return;
        }

        $fail('validation.translatable_required')->translate([
            'locales' => $this->readable($missing),
        ]);
    }

    /**
     * Required locales this value has nothing usable in.
     *
     * The same call the observer makes, so a value this form accepts is one the
     * save accepts, and the other way round.
     *
     * @return list<string>
     */
    public function missingLocales(mixed $value): array
    {
        return TranslationValue::missingLocales($value, $this->locales ?? LocaleResolver::required());
    }

    /**
     * Locale codes as an operator would name them.
     *
     * "Ελληνικά, English" rather than "el, en". The codes are ours; the
     * operator picked their language from a switcher that said Ελληνικά, and a
     * message that answers in ISO codes is asking them to learn our vocabulary
     * to fix their own typo.
     *
     * @param  list<string>  $locales
     */
    private function readable(array $locales): string
    {
        return implode(', ', array_map(
            static fn (string $locale): string => (string) __("enums.locale.{$locale}.label"),
            $locales,
        ));
    }
}
