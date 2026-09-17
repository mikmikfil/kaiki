<?php

declare(strict_types=1);

namespace App\Domain\Hosted\Support;

use App\Domain\Hosted\Actions\SaveHomePage;
use App\Enums\HomeBlockType;
use App\Models\HomePageBlock;

/**
 * What each list-shaped block keeps in `items`, and nothing else (2026-09-16).
 *
 * The same discipline {@see BlockSettings} applies to `settings`: the column is
 * JSON an operator writes into, so it holds exactly the fields named here, with
 * values of the type stated here, for every row — whether the editor wrote it,
 * a seeder did, or a future import will. It runs on the way in
 * ({@see SaveHomePage}) and on the way out
 * ({@see HomePageBlock::entries()}), so a row written against an older
 * shape renders rather than throws.
 *
 * ## Still no markup
 *
 * Every text field is a plain string, translated per locale and trimmed to a
 * length. Nothing is a URL an operator typed: a button names a **target** from a
 * closed list, and the one free-form target is a path on the operator's own
 * hosted site, matched against a pattern that admits no scheme, no host and no
 * `//`. An icon is a name from {@see self::ICONS}, never an SVG.
 *
 * ## Entries that say nothing are dropped
 *
 * A figure with no value, a step with no title, a review with no text. Kept,
 * each would render as an empty box on a page a guest is reading.
 */
final class BlockItems
{
    /** The icons an operator may put beside a reason or a trust badge. */
    public const ICONS = ['users', 'anchor', 'shield', 'lock', 'star', 'heart', 'sun', 'boat', 'clock', 'pin', 'check', 'support'];

    /**
     * The icons of the four reasons a new «Γιατί εμάς» block starts with, in
     * the order of `hosted.blocks.features.defaults`. Here rather than in the
     * lang files because an icon name is the same word in every language.
     */
    public const STARTING_FEATURE_ICONS = ['users', 'anchor', 'sun', 'lock'];

    /** Where a call-to-action button may point. */
    public const TARGETS = ['trips', 'search', 'contact', 'trip', 'page'];

    /**
     * The stored entries, cleaned, or null when none survive.
     *
     * @return list<array<string, mixed>>|null
     */
    public static function normalise(HomeBlockType $type, mixed $stored): ?array
    {
        if (! $type->hasItems() || ! is_array($stored)) {
            return null;
        }

        $items = [];

        foreach ($stored as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $item = match ($type) {
                HomeBlockType::Hero => self::badge($entry),
                HomeBlockType::Stats => self::stat($entry),
                HomeBlockType::Steps => self::step($entry),
                HomeBlockType::Features => self::feature($entry),
                HomeBlockType::Testimonials => self::review($entry),
                default => null,
            };

            if ($item !== null) {
                $items[] = $item;
            }

            if (count($items) === $type->maxItems()) {
                break;
            }
        }

        return $items === [] ? null : $items;
    }

    /**
     * The stored buttons of a hero or a call-to-action band, cleaned, or null.
     *
     * @return list<array{label: array<string, string>, target: string, product_id: int|null, path: string|null}>|null
     */
    public static function buttons(HomeBlockType $type, mixed $stored): ?array
    {
        if ($type->maxButtons() === 0 || ! is_array($stored)) {
            return null;
        }

        $buttons = [];

        foreach ($stored as $entry) {
            $button = is_array($entry) ? self::button($entry) : null;

            if ($button !== null) {
                $buttons[] = $button;
            }

            if (count($buttons) === $type->maxButtons()) {
                break;
            }
        }

        return $buttons === [] ? null : $buttons;
    }

    /**
     * An uploaded file's path on the public disk, or null.
     *
     * The upload field hands back a one-entry array or a string; either way it
     * is a relative path the form wrote, and anything that could climb out of
     * the disk is refused rather than stored.
     */
    public static function upload(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        if (! is_string($value) || $value === '' || str_contains($value, '..') || ! preg_match('~^[A-Za-z0-9._\-/]{1,255}$~', $value)) {
            return null;
        }

        return ltrim($value, '/');
    }

    /**
     * One translated field in the locale being read, falling back to the other.
     *
     * A wrong-language line is worth more on the page than an empty box, which
     * is the rule {@see HomePageBlock::galleryImages()} already
     * applies to alt text.
     *
     * @param  array<string, mixed>  $item
     */
    public static function text(array $item, string $key, string $locale): string
    {
        $value = $item[$key] ?? null;

        if (! is_array($value)) {
            return '';
        }

        return (string) ($value[$locale] ?? $value['el'] ?? $value['en'] ?? '');
    }

    /**
     * A path on the operator's own site, or null.
     *
     * Letters, digits and the punctuation a path and a query use — and nothing
     * that could start another origin: no scheme, no `//`, no backslash.
     */
    public static function path(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $raw = trim($value);

        // Checked before the leading slash comes off: `//host/path` is a
        // protocol-relative address, not a path with a spare slash.
        if (str_contains($raw, '//')) {
            return null;
        }

        $path = ltrim($raw, '/');

        if ($path === '' || ! preg_match('@^[A-Za-z0-9._~\-/?=&#%]{1,200}$@', $path)) {
            return null;
        }

        return $path;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{icon: string, text: array<string, string>}|null
     */
    private static function badge(array $entry): ?array
    {
        $text = self::translations($entry['text'] ?? null, 48);

        return $text === null ? null : ['icon' => self::icon($entry['icon'] ?? null), 'text' => $text];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{icon: string|null, value: array<string, string>, label: array<string, string>}|null
     */
    private static function stat(array $entry): ?array
    {
        $value = self::translations($entry['value'] ?? null, 16);

        return $value === null ? null : [
            // Optional, unlike a reason's: a figure reads on its own.
            'icon' => is_string($entry['icon'] ?? null) && in_array($entry['icon'], self::ICONS, true) ? $entry['icon'] : null,
            'value' => $value,
            'label' => self::translations($entry['label'] ?? null, 60) ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{title: array<string, string>, text: array<string, string>}|null
     */
    private static function step(array $entry): ?array
    {
        $title = self::translations($entry['title'] ?? null, 80);

        return $title === null ? null : [
            'title' => $title,
            'text' => self::translations($entry['text'] ?? null, 300) ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{icon: string, title: array<string, string>, text: array<string, string>}|null
     */
    private static function feature(array $entry): ?array
    {
        $title = self::translations($entry['title'] ?? null, 80);

        return $title === null ? null : [
            'icon' => self::icon($entry['icon'] ?? null),
            'title' => $title,
            'text' => self::translations($entry['text'] ?? null, 300) ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{quote: array<string, string>, name: string, trip: array<string, string>, rating: int, avatar: string|null}|null
     */
    private static function review(array $entry): ?array
    {
        $quote = self::translations($entry['quote'] ?? null, 600);

        if ($quote === null) {
            return null;
        }

        $rating = is_numeric($entry['rating'] ?? null) ? (int) $entry['rating'] : 5;

        return [
            'quote' => $quote,
            // A person's name is not translated: «Ελένη Π.» is «Ελένη Π.» on
            // the English page too.
            'name' => is_string($entry['name'] ?? null) ? mb_substr(trim($entry['name']), 0, 60) : '',
            'trip' => self::translations($entry['trip'] ?? null, 80) ?? [],
            'rating' => max(1, min(5, $rating)),
            // Optional. Without one the card shows the name's first letter.
            'avatar' => self::upload($entry['avatar'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{label: array<string, string>, target: string, product_id: int|null, path: string|null}|null
     */
    private static function button(array $entry): ?array
    {
        $label = self::translations($entry['label'] ?? null, 40);
        $target = is_string($entry['target'] ?? null) ? $entry['target'] : null;

        if ($label === null || ! in_array($target, self::TARGETS, true)) {
            return null;
        }

        $productId = is_numeric($entry['product_id'] ?? null) ? (int) $entry['product_id'] : null;
        $path = self::path($entry['path'] ?? null);

        // A button that names a trip without one, or a page without a path,
        // has nowhere to go — dropped rather than pointed at the home page.
        if (($target === 'trip' && $productId === null) || ($target === 'page' && $path === null)) {
            return null;
        }

        return [
            'label' => $label,
            'target' => $target,
            'product_id' => $target === 'trip' ? $productId : null,
            'path' => $target === 'page' ? $path : null,
        ];
    }

    private static function icon(mixed $value): string
    {
        return is_string($value) && in_array($value, self::ICONS, true) ? $value : 'check';
    }

    /**
     * A translated value, trimmed and cut to length, or null when blank in
     * every locale — the rule `SaveHomePage` applies to headings.
     *
     * @return array<string, string>|null
     */
    private static function translations(mixed $value, int $max): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $out = [];

        foreach ($value as $locale => $text) {
            if (is_string($locale) && is_string($text) && trim($text) !== '') {
                $out[$locale] = mb_substr(trim($text), 0, $max);
            }
        }

        return $out === [] ? null : $out;
    }
}
