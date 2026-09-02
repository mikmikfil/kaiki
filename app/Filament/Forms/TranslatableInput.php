<?php

declare(strict_types=1);

namespace App\Filament\Forms;

use App\Rules\TranslatableRequired;
use App\Support\Locale\LocaleResolver;
use Closure;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

/**
 * One input per locale for a translatable column, built the same way every time.
 *
 * `docs/data-model.md` §1.6 requires **both** `el` and `en` on every
 * translatable column, and `SearchIndexObserver` refuses a save that is missing
 * either. A form that offered a single box would therefore be a form that
 * throws on submit, so every translatable field in the panel goes through here.
 *
 * ## Why a helper rather than a copied Tabs block
 *
 * M1 adds translatable fields to vessels, ports, products, extras, age bands,
 * seasons and cancellation policies. Copied, the block would drift — one
 * resource forgets `TranslatableRequired`, another hardcodes `['el', 'en']` and
 * silently stops offering the third locale EXT-7 promises is a lang-file
 * addition. The locale list comes from {@see LocaleResolver} so that promise
 * keeps being true.
 *
 * ## Why the rule is attached per locale
 *
 * The whole-set form of {@see TranslatableRequired} can only report on one
 * field, which means marking the Greek input red to say the English one is
 * empty. `forLocale()` lets each input answer for itself, while both still end
 * at the one shared definition of "blank".
 */
final class TranslatableInput
{
    /**
     * A tab per locale, each holding one single-line input.
     *
     * @param  string  $name  the translatable attribute, e.g. `name`
     */
    public static function text(string $name, string $label, ?string $helperText = null, bool $required = true, ?int $maxLength = null): Component
    {
        return self::tabs($name, $label, $helperText, static function (string $path, string $locale) use ($required, $maxLength): TextInput {
            $input = TextInput::make($path);

            if ($maxLength !== null) {
                $input->maxLength($maxLength);
            }

            return self::applyRequired($input, $locale, $required);
        });
    }

    /**
     * A tab per locale, each holding one multi-line input.
     */
    public static function textarea(string $name, string $label, ?string $helperText = null, bool $required = false, int $rows = 4): Component
    {
        return self::tabs($name, $label, $helperText, static fn (string $path, string $locale): Textarea => self::applyRequired(
            Textarea::make($path)->rows($rows),
            $locale,
            $required,
        ));
    }

    /**
     * @param  Closure(string, string): (TextInput|Textarea)  $build
     */
    private static function tabs(string $name, string $label, ?string $helperText, Closure $build): Component
    {
        $tabs = [];

        foreach (LocaleResolver::installed() as $locale) {
            $tabs[] = Tabs\Tab::make($locale)
                // The language names itself — "Ελληνικά", not "Greek" — for the
                // same reason the switcher does: someone hunting for the Greek
                // tab recognises the Greek word.
                ->label(__("enums.locale.{$locale}.label"))
                ->schema([
                    $build("{$name}.{$locale}", $locale)
                        ->label($label)
                        ->helperText($helperText),
                ]);
        }

        // Keyed by the attribute so two translatable fields on one form do not
        // share a tab state and switch together.
        return Tabs::make($name)->tabs($tabs)->columnSpanFull();
    }

    /**
     * @template T of TextInput|Textarea
     *
     * @param  T  $input
     * @return T
     */
    private static function applyRequired(TextInput|Textarea $input, string $locale, bool $required): TextInput|Textarea
    {
        if (! $required) {
            return $input;
        }

        // `rules()` rather than `required()`: Filament's own required marker
        // would report "The name.en field is required", which names a column
        // and a locale code at an operator. The #15 rule says which language is
        // missing, in the operator's language.
        return $input
            ->rules([TranslatableRequired::forLocale($locale)])
            ->markAsRequired();
    }
}
