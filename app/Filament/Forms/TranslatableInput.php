<?php

declare(strict_types=1);

namespace App\Filament\Forms;

use App\Rules\TranslatableRequired;
use App\Support\Locale\LocaleResolver;
use Closure;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Group;
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
    /**
     * @param  Closure(TextInput, string): TextInput|null  $configure  applied per
     *                                                                 locale after the input is built, for a form that has to react to
     *                                                                 one language's field — the trip slug is generated from the Greek
     *                                                                 title, and nothing else in the product needs to know that.
     */
    public static function text(
        string $name,
        string $label,
        ?string $helperText = null,
        bool $required = true,
        ?int $maxLength = null,
        ?Closure $configure = null,
    ): Component {
        return self::tabs($name, $label, $helperText, static function (string $path, string $locale) use ($required, $maxLength, $configure): TextInput {
            $input = TextInput::make($path);

            if ($maxLength !== null) {
                $input->maxLength($maxLength);
            }

            $input = self::applyRequired($input, $locale, $required);

            return $configure === null ? $input : $configure($input, $locale);
        });
    }

    /**
     * A tab per locale, each holding one multi-line input.
     *
     * `maxLength` brings a live character count with it (2026-09-23): a limit
     * an operator only meets by being refused is a limit that costs them the
     * sentence they had just finished writing.
     */
    public static function textarea(string $name, string $label, ?string $helperText = null, bool $required = false, int $rows = 4, ?int $maxLength = null): Component
    {
        return self::tabs($name, $label, $helperText, static function (string $path, string $locale) use ($required, $rows, $maxLength): Textarea {
            $input = Textarea::make($path)->rows($rows);

            if ($maxLength !== null) {
                $input->maxLength($maxLength)->live(onBlur: true);
            }

            return self::applyRequired($input, $locale, $required);
        });
    }

    /**
     * One input per locale, all of them in the page, one of them on screen.
     *
     * ## Why this stopped being a pair of tabs per field (2026-09-18)
     *
     * It was one `Tabs` per translatable attribute, keyed by the attribute so
     * that two fields did not switch together — which is exactly what made the
     * trip form ask «Ελληνικά ή English;» eight times on one page, and let an
     * operator write the summary in Greek and the description in English
     * without ever seeing that they had. The form now asks once, at the top
     * ({@see resources/views/filament/app/form-locale-switch.blade.php}), and
     * every field answers to that switch.
     *
     * The mechanism is a class per locale and a `display: none` rule, so both
     * languages stay in the form's state and are submitted together. A field
     * that was removed from the page would take its validation error with it.
     *
     * @param  Closure(string, string): (TextInput|Textarea)  $build
     */
    private static function tabs(string $name, string $label, ?string $helperText, Closure $build): Component
    {
        $inputs = [];

        foreach (LocaleResolver::installed() as $locale) {
            $inputs[] = Group::make([
                $build("{$name}.{$locale}", $locale)
                    // The language names itself — «Ελληνικά», not «Greek» — on
                    // the label of the second and later locales, so a form
                    // switched to English still says which language it is
                    // collecting. The first locale is the operator's own and
                    // needs no announcement.
                    ->label($locale === LocaleResolver::installed()[0]
                        ? $label
                        : $label . ' · ' . __("enums.locale.{$locale}.label"))
                    ->helperText($helperText),
            ])->extraAttributes(['class' => "ka-locale ka-locale--{$locale}"]);
        }

        return Group::make($inputs)->columnSpanFull();
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
