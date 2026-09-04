<?php

declare(strict_types=1);

use App\Domain\Branding\Support\ContrastChecker;

/*
|--------------------------------------------------------------------------
| WCAG contrast — spec BRD-5
|--------------------------------------------------------------------------
|
| Two pairs, two thresholds: body text on background at 4.5:1, button text on
| the primary colour at 3:1 (a UI component). **It warns; it never blocks** —
| that part is asserted where saving happens, in BrandingPageTest.
|
| The ratios below are the WCAG formula's, computed independently and pinned as
| literals. Asserting `evaluate()` against `ratio()` would only prove the class
| agrees with itself.
|
*/

it('computes the known ratios of the extremes', function (): void {
    // Black on white is 21:1 by definition, and a colour against itself is
    // 1:1. If the gamma handling is wrong these two still pass — which is why
    // the mid-tones below matter more than they look.
    expect(ContrastChecker::ratio('#000000', '#FFFFFF'))->toBe(21.0)
        ->and(ContrastChecker::ratio('#FFFFFF', '#FFFFFF'))->toBe(1.0)
        ->and(ContrastChecker::ratio('#0F62FE', '#0F62FE'))->toBe(1.0);
})->group('fast');

it('does not care which colour is given first', function (): void {
    // The formula puts the lighter colour on top itself, so a caller never has
    // to know which of the two it passed first.
    expect(ContrastChecker::ratio('#101828', '#FFFFFF'))
        ->toBe(ContrastChecker::ratio('#FFFFFF', '#101828'));
})->group('fast');

it('linearises the channels rather than treating them as linear', function (): void {
    // The mistake that makes this look like three multiplications. sRGB is
    // gamma-encoded, and treating the channel values as linear **overstates the
    // contrast of every dark colour** — mid-grey on white is 4.6:1 with the
    // correct transfer function and about 2:1 without it, which is the
    // difference between passing and failing AA.
    //
    // #767676 on white is the canonical WCAG example: it is the lightest grey
    // that still passes 4.5:1.
    expect(ContrastChecker::ratio('#767676', '#FFFFFF'))->toBe(4.54)
        ->and(ContrastChecker::ratio('#777777', '#FFFFFF'))->toBe(4.48);
})->group('fast');

it('passes both thresholds for the platform default palette', function (): void {
    // The defaults BRD-3 writes are the palette every operator starts with, so
    // a failure here would mean every new tenant sees a warning on a screen
    // they have not touched yet.
    $results = ContrastChecker::evaluate('#101828', '#FFFFFF', '#0F62FE');

    expect($results[ContrastChecker::BODY_TEXT]['passes'])->toBeTrue()
        ->and($results[ContrastChecker::BODY_TEXT]['threshold'])->toBe(4.5)
        ->and($results[ContrastChecker::BUTTON_TEXT]['passes'])->toBeTrue()
        ->and($results[ContrastChecker::BUTTON_TEXT]['threshold'])->toBe(3.0);
})->group('fast');

it('fails body text at 4.5 and button text at 3, independently', function (): void {
    // The two thresholds are different numbers for a reason, and a pair can
    // fail one and pass the other. #949494 on white is 3.03:1: below the 4.5
    // body-text minimum, above the 3.0 UI-component one.
    $results = ContrastChecker::evaluate('#949494', '#FFFFFF', '#949494');

    expect($results[ContrastChecker::BODY_TEXT]['passes'])->toBeFalse()
        ->and($results[ContrastChecker::BUTTON_TEXT]['passes'])->toBeTrue()
        ->and($results[ContrastChecker::BODY_TEXT]['ratio'])->toBe(3.03);
})->group('fast');

it('judges button text as the background colour on the primary fill', function (): void {
    // BRD-5 says "button text on the primary colour" and §2.2 has no
    // button-text column. The check uses `color_background` as the label
    // colour, which is what a filled button on a light surface renders. The
    // alternative — assuming white — would pass every dark palette and fail
    // every light one regardless of what the widget draws, so this asserts the
    // decision rather than leaving it implicit.
    $onDark = ContrastChecker::evaluate('#FFFFFF', '#101828', '#F5F5F5');

    // A near-white primary with a near-black page: white-on-near-white would
    // be the reading if the label were assumed white, and it would score 1.06.
    // Reading the label as the *background* colour gives near-black on
    // near-white, which is what the button actually looks like.
    expect($onDark[ContrastChecker::BUTTON_TEXT]['ratio'])->toBeGreaterThan(15.0)
        ->and($onDark[ContrastChecker::BUTTON_TEXT]['passes'])->toBeTrue();
})->group('fast');

it('treats a malformed colour as black, the direction a warning should fail in', function (): void {
    // The column is char(7) and validated on every write, so a bad value here
    // means a row written around the form. Black makes the worst pair look
    // worst, which is the safe direction for a warning: it over-reports rather
    // than staying quiet about a palette nobody can read.
    expect(ContrastChecker::ratio('not a colour', '#FFFFFF'))->toBe(21.0)
        ->and(ContrastChecker::ratio('#GGGGGG', '#FFFFFF'))->toBe(21.0)
        ->and(ContrastChecker::ratio('#FFF', '#FFFFFF'))->toBe(21.0);
})->group('fast');

it('reads both thresholds from config rather than hardcoding them', function (): void {
    // BRD-5's numbers are in `config('kaiki.branding.contrast')`, and a checker
    // that ignored them would make the config decorative — the panel would show
    // one threshold and the check would apply another.
    config()->set('kaiki.branding.contrast.body_text_ratio', 21.0);
    config()->set('kaiki.branding.contrast.ui_component_ratio', 21.0);

    $results = ContrastChecker::evaluate('#000000', '#FFFFFF', '#000000');

    expect($results[ContrastChecker::BODY_TEXT]['threshold'])->toBe(21.0)
        ->and($results[ContrastChecker::BODY_TEXT]['passes'])->toBeTrue();

    config()->set('kaiki.branding.contrast.body_text_ratio', 21.5);

    expect(ContrastChecker::evaluate('#000000', '#FFFFFF', '#000000')[ContrastChecker::BODY_TEXT]['passes'])
        ->toBeFalse();
})->group('fast');
