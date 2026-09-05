<?php

declare(strict_types=1);

namespace App\Enums\Concerns;

use Illuminate\Support\Str;

/**
 * Resolves an enum's operator-facing label from `lang/{locale}/enums.php`.
 *
 * CNV-11: an error message or a label that reaches an operator exists in Greek
 * and English and comes from a lang file, never from a literal in the enum.
 * `NoHardcodedStringsTest` enforces the negative; this trait supplies the
 * positive, so that adding an enum case is a lang-file edit rather than an
 * invitation to type a string into PHP.
 *
 * The key is derived — `ApiKeyType` reads `enums.api_key_type` — so an enum
 * cannot drift from its own lang block by being renamed in one place only.
 *
 * @phpstan-require-implements \BackedEnum
 */
trait HasTranslatedLabel
{
    /** The operator-facing name of this case. */
    public function label(): string
    {
        return $this->line('label');
    }

    /**
     * The label in one named locale, whatever the request's locale is.
     *
     * `docs/api.md` §4.1 requires **both** languages in every API error, always
     * — a guest can hit a refusal mid-locale-switch, and shipping both strings
     * removes the whole class of "the error came back in the wrong language".
     * `label()` answers for the current locale only, which is right for a form
     * and useless for an envelope that has to carry two.
     */
    public function labelIn(string $locale): string
    {
        return $this->line('label', $locale);
    }

    /** `enums.api_key_type` for `App\Enums\ApiKeyType`. */
    public static function translationNamespace(): string
    {
        return 'enums.' . Str::snake(class_basename(static::class));
    }

    /**
     * value => label, for a form select.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (static::cases() as $case) {
            $options[(string) $case->value] = $case->label();
        }

        return $options;
    }

    /**
     * Fetches the enum's whole block and indexes it, rather than asking for
     * `enums.api_scope.products.read.label`.
     *
     * **Several enum values contain a dot**, and Laravel reads a dot in a
     * translation key as a path separator — so the direct lookup searches for
     * `api_scope → products → read → label`, finds nothing, and returns the key.
     * That bug shipped in #6 and was found in #10 only because a form finally
     * rendered those labels. Doing it this way once, here, means the next enum
     * with a dotted value inherits the fix instead of rediscovering it.
     */
    protected function line(string $key, ?string $locale = null): string
    {
        $namespace = static::translationNamespace();

        /** @var array<string, mixed> $block */
        $block = (array) trans($namespace, [], $locale);

        $lines = $block[$this->value] ?? null;

        if (is_array($lines) && is_string($lines[$key] ?? null)) {
            return $lines[$key];
        }

        // Falls back to the dotted key, never to the raw value. Returning
        // `past_due` would be a plausible lowercase string on screen — a
        // missing translation nobody reports, and an assertion that the label
        // is not the key could then never fail. I18N-1 relies on a missing
        // string looking missing.
        return "{$namespace}.{$this->value}.{$key}";
    }
}
