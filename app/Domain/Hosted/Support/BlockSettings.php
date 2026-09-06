<?php

declare(strict_types=1);

namespace App\Domain\Hosted\Support;

use App\Enums\HomeBlockType;
use App\Enums\ProductCategory;

/**
 * What each block type is allowed to keep in `settings`, and nothing else.
 *
 * ## Why a whitelist rather than "store what the form sent"
 *
 * `settings` is a JSON column an operator writes into, which makes it the one
 * place in the schema where a future feature could arrive by accident: somebody
 * adds a form field, it lands in the blob, a template starts reading it, and now
 * there is an undocumented setting with no default, no validation and no lang
 * key. Normalising on the way in means the column holds exactly the keys this
 * class names, with values of the type it says, for every row — including rows
 * written by a seeder, a factory, an import or a future API.
 *
 * ## Unknown keys are dropped, not refused
 *
 * A refusal would break every existing row the day a setting is removed. This
 * runs on read as well as on write, so a block written against an older shape
 * renders with today's defaults instead of throwing on a page a guest is
 * looking at.
 */
final class BlockSettings
{
    /** The trips block shows everything the catalogue has. */
    public const SOURCE_ALL = 'all';

    /** Only products the operator marked `is_featured`. */
    public const SOURCE_FEATURED = 'featured';

    /** One `ProductCategory`. */
    public const SOURCE_CATEGORY = 'category';

    /**
     * The defaults for a type, which are also the complete list of its keys.
     *
     * @return array<string, mixed>
     */
    public static function defaults(HomeBlockType $type): array
    {
        return match ($type) {
            HomeBlockType::Hero => [
                // Where the one button goes. Not a free URL: an operator who can
                // type a URL can type an off-site one, and a hero button that
                // leaves the booking page is the most expensive mistake this
                // editor could let somebody make.
                'cta' => 'trips',
            ],
            HomeBlockType::Trips => [
                'source' => self::SOURCE_ALL,
                'category' => null,
                // Zero means "no limit". A null would need explaining in the
                // form, and an operator who wants everything picks the source
                // rather than the number.
                'limit' => 0,
            ],
            HomeBlockType::Story => [
                'image_side' => 'right',
            ],
            HomeBlockType::Gallery => [
                'columns' => 3,
            ],
            HomeBlockType::Contact => [
                'show_phone' => true,
                'show_email' => true,
                'show_address' => true,
                // The meeting point is a `ports` row the operator already has,
                // so the block cannot disagree with the product pages about
                // where the boat leaves from.
                'meeting_point_id' => null,
            ],
        };
    }

    /**
     * The stored settings, filled in and cleaned up.
     *
     * @param  array<array-key, mixed>|null  $stored
     * @return array<string, mixed>
     */
    public static function normalise(HomeBlockType $type, ?array $stored): array
    {
        $defaults = self::defaults($type);
        $settings = $defaults;

        foreach ($defaults as $key => $default) {
            if (! array_key_exists($key, (array) $stored)) {
                continue;
            }

            $settings[$key] = self::coerce($key, $stored[$key], $default);
        }

        return self::reconcile($type, $settings);
    }

    /**
     * Cast a stored value to the shape its default declares.
     *
     * A form posts `"1"` for a checkbox and `"3"` for a number; a JSON column
     * hands both back as strings. Comparing those with `===` against `true` or
     * `3` — which CNV's `strict_comparison` requires everywhere — silently
     * fails, and the block renders the default while the operator swears they
     * changed it.
     */
    private static function coerce(string $key, mixed $value, mixed $default): mixed
    {
        return match (true) {
            is_bool($default) => filter_var($value, FILTER_VALIDATE_BOOL),
            is_int($default) => is_numeric($value) ? max(0, (int) $value) : $default,
            $value === null || $value === '' => null,
            is_string($value) => $value,
            default => $default,
        };
    }

    /**
     * Settings that are individually valid and jointly nonsense.
     *
     * `source = category` with no category is a trips block that renders an
     * empty grid on somebody's home page, which is worse than a block that
     * shows everything. It is reconciled rather than refused for the reason the
     * class docblock gives: this runs on read.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private static function reconcile(HomeBlockType $type, array $settings): array
    {
        if ($type !== HomeBlockType::Trips) {
            return $settings;
        }

        $category = is_string($settings['category'] ?? null)
            ? ProductCategory::tryFrom($settings['category'])
            : null;

        $settings['category'] = $category?->value;

        if ($settings['source'] === self::SOURCE_CATEGORY && $category === null) {
            $settings['source'] = self::SOURCE_ALL;
        }

        if (! in_array($settings['source'], [self::SOURCE_ALL, self::SOURCE_FEATURED, self::SOURCE_CATEGORY], true)) {
            $settings['source'] = self::SOURCE_ALL;
        }

        return $settings;
    }
}
