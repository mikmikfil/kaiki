<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Illuminate\Support\HtmlString;

/**
 * The primary colour's scale for the panels' dark mode (2026-09-23).
 *
 * `Color::hex()` makes one scale from the platform's primary, and Filament uses
 * it in both themes. Kaiki's primary is a deep navy (#123A5E), so in dark mode
 * every button, link, toggle and chart bar was navy on near-black: 1.5:1 for a
 * primary button against its card, 3:1 for a link. The light theme is right
 * and is left alone; dark mode gets a second, lighter scale of the **same
 * hue**, written as the `--primary-*` variables Filament reads, under
 * `:root.dark` — which outranks Filament's own `:root` block wherever the two
 * land in the page.
 *
 * The hue is the brand's, so a platform that picks another colour on `/admin`
 * → Εμφάνιση still gets its own colour in the dark, only lighter. Saturation
 * and lightness per step are fixed, taken from the scale drawn for the default
 * navy (400 #8DB8F0, 500 #7FB0EE — 7.9:1 on Filament's dark card); a nearly
 * grey brand keeps its greyness rather than being painted blue-bright.
 *
 * The 600 step is held down so a white toggle thumb or tick on it keeps 3:1.
 *
 * The one thing a lighter 500 breaks is Filament's white label on a primary
 * button (2.3:1). The theme stylesheet puts the dark end of this same scale on
 * those labels instead — see `resources/css/filament/app/theme.css`.
 */
final class DarkPrimary
{
    /**
     * Step => [saturation, lightness], both 0–1, from the reference scale.
     *
     * @var array<int, array{0: float, 1: float}>
     */
    private const STEPS = [
        50 => [0.750, 0.969],
        100 => [0.758, 0.935],
        200 => [0.746, 0.876],
        300 => [0.755, 0.808],
        400 => [0.767, 0.747],
        500 => [0.766, 0.716],
        600 => [0.610, 0.580], // white on it (toggle thumb, tick) 3.1:1
        700 => [0.486, 0.512],
        800 => [0.498, 0.414],
        900 => [0.522, 0.312],
        950 => [0.556, 0.159],
    ];

    /** The platform's default primary, for a stored value that is not a colour. */
    private const FALLBACK = '#123A5E';

    /** The default navy's saturation; a brand this saturated or more keeps the table as it is. */
    private const REFERENCE_SATURATION = 0.6;

    /**
     * The eleven shades as Filament's `r, g, b` triplets.
     *
     * @return array<int, string>
     */
    public static function shades(string $hex): array
    {
        [$hue, $saturation] = self::hueAndSaturation($hex);

        $scale = min(1.0, $saturation / self::REFERENCE_SATURATION);

        $shades = [];

        foreach (self::STEPS as $step => [$s, $l]) {
            $shades[$step] = implode(', ', self::rgb($hue, $s * $scale, $l));
        }

        return $shades;
    }

    /** The `<style>` block for the panel's head. */
    public static function style(string $hex): HtmlString
    {
        $variables = '';

        foreach (self::shades($hex) as $step => $rgb) {
            $variables .= "--primary-{$step}:{$rgb};";
        }

        return new HtmlString("<style>:root.dark{{$variables}}</style>");
    }

    /**
     * @return array{0: float, 1: float} hue in degrees, saturation 0–1
     */
    private static function hueAndSaturation(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (preg_match('/^[0-9a-fA-F]{6}$/', $hex) !== 1) {
            // Not a colour we can read: the default navy's scale.
            return self::hueAndSaturation(self::FALLBACK);
        }

        [$r, $g, $b] = array_map(
            static fn (string $pair): float => hexdec($pair) / 255,
            str_split($hex, 2),
        );

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $delta = $max - $min;

        if ($delta === 0.0) {
            return [0.0, 0.0];
        }

        $lightness = ($max + $min) / 2;
        $saturation = $lightness > 0.5 ? $delta / (2 - $max - $min) : $delta / ($max + $min);

        $hue = match ($max) {
            $r => fmod(($g - $b) / $delta + 6, 6),
            $g => ($b - $r) / $delta + 2,
            default => ($r - $g) / $delta + 4,
        };

        return [$hue * 60, $saturation];
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function rgb(float $hue, float $saturation, float $lightness): array
    {
        $chroma = (1 - abs(2 * $lightness - 1)) * $saturation;
        $x = $chroma * (1 - abs(fmod($hue / 60, 2) - 1));
        $m = $lightness - $chroma / 2;

        [$r, $g, $b] = match (true) {
            $hue < 60 => [$chroma, $x, 0.0],
            $hue < 120 => [$x, $chroma, 0.0],
            $hue < 180 => [0.0, $chroma, $x],
            $hue < 240 => [0.0, $x, $chroma],
            $hue < 300 => [$x, 0.0, $chroma],
            default => [$chroma, 0.0, $x],
        };

        return [
            (int) round(($r + $m) * 255),
            (int) round(($g + $m) * 255),
            (int) round(($b + $m) * 255),
        ];
    }
}
