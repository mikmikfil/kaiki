<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Observers\SearchIndexObserver;
use App\Rules\TranslatableRequired;
use RuntimeException;

/**
 * A translatable field was saved without every locale `docs/data-model.md` §1.6
 * requires.
 *
 * §1.6 is explicit — *"Both keys are required on write; a model observer rejects
 * a translation set missing `el` or `en`"* — so this is thrown from
 * {@see SearchIndexObserver}, not merely reported. It is the last line of
 * defence and is expected to be **unreachable in normal use**: forms validate
 * with {@see TranslatableRequired} and show the operator a Greek error beside
 * the field, which is the humane version of the same rule.
 *
 * The reason it exists anyway is imports and the API. A CSV import that writes
 * Greek titles and no English ones would otherwise fill the catalogue with
 * products whose English page renders the Greek text via the I18N-5 fallback —
 * which looks *almost* right, so nobody reports it, and it is discovered months
 * later when an English-speaking guest asks what a καΐκι is.
 */
final class MissingTranslationException extends RuntimeException
{
    /**
     * @param  list<string>  $locales
     */
    public static function forAttribute(string $model, string $attribute, array $locales): self
    {
        return new self(sprintf(
            '[%s::$%s] has no translation for [%s]. Every locale in '
            . 'config(\'kaiki.i18n.required_locales\') must be filled in before the model is saved '
            . '(docs/data-model.md §1.6).',
            $model,
            $attribute,
            implode(', ', $locales),
        ));
    }
}
