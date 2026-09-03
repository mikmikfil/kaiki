<?php

declare(strict_types=1);

namespace App\Domain\Branding\Support;

/**
 * WCAG 2.1 contrast for the two pairs BRD-5 names.
 *
 * **It warns; it does not block.** An operator whose colours have been on their
 * boats for fifteen years is not going to be told by a booking system that
 * their brand is wrong, and a save that fails on a contrast ratio is a save
 * they will work around by giving up. The result is stored on the row
 * (`brand_profiles.contrast_warnings`) so the panel can show the badge without
 * recomputing, and so the number an operator was shown is the number that was
 * true when they last saved.
 *
 * ## Which pairs, and why button text is `color_background`
 *
 * BRD-5 names "body text on background" and "button text on the primary
 * colour". The first is `color_text` on `color_background` and is unambiguous.
 * The second is not: **there is no button-text column** in `docs/data-model.md`
 * §2.2, and adding one is a schema change this issue is not entitled to make.
 *
 * So the check uses `color_background` as the button's label colour, which is
 * what a filled button on a light surface actually renders — the label is the
 * surface colour punched out of the primary fill. That is a decision, not a
 * reading of the spec, and it is recorded here because the alternative
 * (assuming white) would quietly pass every dark palette and quietly fail every
 * light one regardless of what the widget draws.
 */
final class ContrastChecker
{
    /** Body text on the page background — WCAG AA for text. */
    public const BODY_TEXT = 'body_text';

    /** Button label on the primary fill — WCAG AA for UI components. */
    public const BUTTON_TEXT = 'button_text';

    /**
     * The stored shape of `brand_profiles.contrast_warnings`.
     *
     * @return array<string, array{ratio: float, threshold: float, passes: bool}>
     */
    public static function evaluate(
        string $colorText,
        string $colorBackground,
        string $colorPrimary,
    ): array {
        $bodyThreshold = (float) config('kaiki.branding.contrast.body_text_ratio');
        $uiThreshold = (float) config('kaiki.branding.contrast.ui_component_ratio');

        return [
            self::BODY_TEXT => self::result(self::ratio($colorText, $colorBackground), $bodyThreshold),
            self::BUTTON_TEXT => self::result(self::ratio($colorBackground, $colorPrimary), $uiThreshold),
        ];
    }

    /**
     * The WCAG contrast ratio between two `#RRGGBB` colours, 1.0 to 21.0.
     *
     * Order does not matter: the formula puts the lighter colour on top itself,
     * which is why a caller never has to know which of the two it passed first.
     */
    public static function ratio(string $foreground, string $background): float
    {
        $lighter = max(self::relativeLuminance($foreground), self::relativeLuminance($background));
        $darker = min(self::relativeLuminance($foreground), self::relativeLuminance($background));

        return round(($lighter + 0.05) / ($darker + 0.05), 2);
    }

    /**
     * WCAG relative luminance.
     *
     * The 0.03928 threshold and the 2.4 exponent are the specification's, not a
     * choice — sRGB is gamma-encoded with a linear toe near black, and treating
     * the channel values as linear (the mistake that makes this look like three
     * multiplications) overstates the contrast of every dark colour.
     */
    private static function relativeLuminance(string $hex): float
    {
        [$r, $g, $b] = self::channels($hex);

        return 0.2126 * self::linearize($r)
            + 0.7152 * self::linearize($g)
            + 0.0722 * self::linearize($b);
    }

    private static function linearize(int $channel): float
    {
        $value = $channel / 255;

        return $value <= 0.03928
            ? $value / 12.92
            : (($value + 0.055) / 1.055) ** 2.4;
    }

    /**
     * `#RRGGBB` to three 0-255 channels.
     *
     * Anything else is black. The column is `char(7)` and validated by regex on
     * every write, so a malformed value here means a row written around the
     * form — and black is the answer that makes the *worst* pair look worst,
     * which is the direction a warning should fail in.
     *
     * @return array{int, int, int}
     */
    private static function channels(string $hex): array
    {
        if (preg_match('/^#([0-9a-fA-F]{6})$/', trim($hex), $matches) !== 1) {
            return [0, 0, 0];
        }

        return [
            (int) hexdec(substr($matches[1], 0, 2)),
            (int) hexdec(substr($matches[1], 2, 2)),
            (int) hexdec(substr($matches[1], 4, 2)),
        ];
    }

    /** @return array{ratio: float, threshold: float, passes: bool} */
    private static function result(float $ratio, float $threshold): array
    {
        return [
            'ratio' => $ratio,
            'threshold' => $threshold,
            'passes' => $ratio >= $threshold,
        ];
    }
}
