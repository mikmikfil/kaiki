<?php

declare(strict_types=1);

use App\Filament\Support\DarkPrimary;

/*
| The panel's dark-mode primary (2026-09-23). Navy on near-black was 1.5:1 for
| a primary button against its card; these pin the numbers that made the
| lighter scale worth having, so a tweak to the table cannot quietly undo it.
*/

function darkPrimaryLuminance(string $rgb): float
{
    $channel = static function (float $v): float {
        $v /= 255;

        return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    };

    [$r, $g, $b] = array_map('floatval', explode(', ', $rgb));

    return 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);
}

function darkPrimaryContrast(string $a, string $b): float
{
    $x = darkPrimaryLuminance($a);
    $y = darkPrimaryLuminance($b);

    return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
}

it('reads on Filament\'s dark surfaces and carries its own button label', function (): void {
    $shades = DarkPrimary::shades('#123A5E');
    $card = '24, 24, 27'; // gray-900

    expect(array_keys($shades))->toBe([50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950])
        // Links and the active menu item.
        ->and(darkPrimaryContrast($shades[400], $card))->toBeGreaterThan(7.0)
        // A primary button against its card.
        ->and(darkPrimaryContrast($shades[500], $card))->toBeGreaterThan(3.0)
        // Its label, the dark end of the scale (theme.css), resting and hovered.
        ->and(darkPrimaryContrast($shades[950], $shades[500]))->toBeGreaterThan(4.5)
        ->and(darkPrimaryContrast($shades[950], $shades[400]))->toBeGreaterThan(4.5)
        // A white toggle thumb or tick on 600.
        ->and(darkPrimaryContrast('255, 255, 255', $shades[600]))->toBeGreaterThanOrEqual(3.0);
});

it('keeps the brand\'s hue and only the dark variables', function (): void {
    $style = (string) DarkPrimary::style('#1F7A3A');

    expect($style)->toStartWith('<style>:root.dark{--primary-50:')
        ->and($style)->not->toContain('--gray');

    [$r, $g, $b] = array_map('intval', explode(', ', DarkPrimary::shades('#1F7A3A')[500]));

    expect($g)->toBeGreaterThan($r)->and($g)->toBeGreaterThan($b);
});

it('falls back to the default hue for something that is not a colour', function (): void {
    expect(DarkPrimary::shades('not-a-colour'))->toBe(DarkPrimary::shades('#123A5E'));
});
