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
     * Run even when the value is empty or absent.
     *
     * Laravel skips a non-implicit rule whenever the value is `null`, `''` or
     * whitespace (`Validator::presentOrRuleIsImplicit()`) — which is **every
     * case this rule exists to catch**. Without this flag the rule fires only
     * for a value that is already filled in, so the operator who tabbed past
     * the English box sails through the form and is stopped by the observer's
     * exception instead, as a 500 page.
     *
     * It went unnoticed in #15 because the tests there validate whole
     * translation *sets* — an array is never "empty" by that check, so the rule
     * always ran. It surfaces the moment a form binds one input per locale.
     */
    public bool $implicit = true;

    /**
     * @param  list<string>|null  $locales  defaults to the required locales
     * @param  string|null  $single  when set, `$value` is that one locale's raw
     *                               value rather than a whole translation set
     */
    public function __construct(
        private readonly ?array $locales = null,
        private readonly ?string $single = null,
    ) {}

    /**
     * The same requirement, applied to one locale's input on its own.
     *
     * A translatable field renders as one input per locale, and a form that
     * validated the whole set could only put the error on one of them —
     * telling an operator their English is missing by marking the Greek field
     * red. This variant lets each input answer for itself.
     *
     * It is a constructor rather than a second rule class because the thing
     * that must not fork is the definition of *blank*: `getTranslations()`
     * drops nulls but keeps `"   "`, and when the observer and the form each
     * decided that for themselves they disagreed, with the import as the only
     * writer that could get the bad value in (#15). Both paths still end at
     * {@see TranslationValue::isBlank()}.
     */
    public static function forLocale(string $locale): self
    {
        return new self([$locale], $locale);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $missing = $this->single === null
            ? $this->missingLocales($value)
            : $this->missingSingleLocale($value);

        if ($missing === []) {
            return;
        }

        $fail('validation.translatable_required')->translate([
            'locales' => $this->readable($missing),
        ]);
    }

    /**
     * @return list<string>
     */
    private function missingSingleLocale(mixed $value): array
    {
        // Wrapped into a set rather than calling isBlank() directly, so this
        // and the whole-set path go through one function. Two callers of
        // isBlank() would be two chances to add a "but not for arrays" clause
        // to one of them.
        return TranslationValue::missingLocales([$this->single => $value], [(string) $this->single]);
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
