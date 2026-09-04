<?php

declare(strict_types=1);

use App\Domain\Branding\Support\CssSanitizer;

/*
|--------------------------------------------------------------------------
| Operator CSS — spec BRD-2, HOS-8
|--------------------------------------------------------------------------
|
| The operator's stylesheet is rendered inside a `<style>` block on a hosted
| page that takes payments. What must not survive is anything that can
| **execute** or **fetch**; everything else is their own page and their own
| problem.
|
| This is a **table of hostile inputs, not a claim of completeness** — the
| sanitiser removes constructs rather than allow-listing properties, so the only
| honest way to describe its coverage is the list of things it has been shown.
| Each row here is a spelling that a naive implementation passes.
|
*/

dataset('hostile css', [
    'javascript URL in a background' => ['a{background:url(javascript:alert(1))}', 'javascript'],
    'uppercase scheme' => ['a{background:url(JAVASCRIPT:alert(1))}', 'javascript'],
    'whitespace before the colon' => ['a{background:url(javascript : alert(1))}', 'javascript'],
    'vbscript URL' => ['a{background:url(vbscript:msgbox(1))}', 'vbscript'],
    'IE expression()' => ['a{width:expression(alert(1))}', 'expression'],
    'IE behavior binding' => ['a{behavior:url(evil.htc)}', 'behavior:'],
    'Gecko XBL binding' => ['a{-moz-binding:url(evil.xml#x)}', 'binding'],
    'HTML document in a data URI' => ['a{background:url(data:text/html;base64,PHNjcmlwdD4=)}', 'text/html'],
    'style element escape' => ['a{}</style><script>alert(1)</script>', '<script'],
    'at-import of a third party' => ['@import url(https://evil.example/x.css); a{color:red}', '@import'],
    'charset that re-decodes what follows' => ['@charset "UTF-7"; a{color:red}', '@charset'],

    // The escape cases. A sanitiser that pattern-matches before decoding
    // rejects the obvious spelling and passes every one of these.
    'hex escape opening the keyword' => ['a{background:url(\6a avascript:alert(1))}', 'javascript'],
    'zero-padded hex escape mid-keyword' => ['a{background:url(java\000073cript:alert(1))}', 'javascript'],
    'backslash before an ordinary letter' => ['a{background:url(java\script:alert(1))}', 'javascript'],

    // A comment is a token separator to a regex and nothing at all to a browser.
    'comment splitting the keyword' => ['a{background:url(java/**/script:alert(1))}', 'javascript'],

    // One byte, and the whole trick.
    'null byte splitting the keyword' => ["a{background:url(java\0script:alert(1))}", 'javascript'],
]);

it('removes every executing or fetching construct', function (string $css, string $forbidden): void {
    $safe = CssSanitizer::sanitize($css);

    expect(strtolower((string) $safe))->not->toContain(strtolower($forbidden));
})->with('hostile css')->group('fast');

it('leaves ordinary CSS alone', function (): void {
    // The other half of the job, and the half a sanitiser fails quietly. An
    // operator whose stylesheet is silently gutted does not file a bug — they
    // ask for the feature to be switched off.
    $css = <<<'CSS'
    .kaiki-widget {
        color: #101828;
        background: url(https://cdn.example.gr/bg.png);
        border-radius: 8px;
        font-family: "Inter", sans-serif;
    }
    CSS;

    $safe = (string) CssSanitizer::sanitize($css);

    expect($safe)
        ->toContain('#101828')
        ->toContain('https://cdn.example.gr/bg.png')
        ->toContain('border-radius: 8px')
        ->toContain('"Inter", sans-serif');
})->group('fast');

it('keeps scroll-behavior, which a substring match for behavior would delete', function (): void {
    // `behavior` matched as a plain substring also matches `scroll-behavior:
    // smooth` — ordinary modern CSS. This is why the tokens are regex fragments
    // pinned to a property position rather than literals.
    $safe = (string) CssSanitizer::sanitize('html{scroll-behavior:smooth;overscroll-behavior:contain}');

    expect($safe)
        ->toContain('scroll-behavior:smooth')
        ->toContain('overscroll-behavior:contain');
})->group('fast');

it('takes a url() as a whole group rather than splitting it on its semicolon', function (): void {
    // A URL is the one place a `;` legitimately sits inside a declaration. A
    // rule that treats `;` as a boundary cuts
    // `url(data:text/html;base64,...)` in half and the second half survives as
    // debris that still says `base64`.
    $safe = (string) CssSanitizer::sanitize('a{background:url(data:text/html;base64,PHN2Zz48L3N2Zz4=)}');

    expect($safe)->not->toContain('base64');
    expect($safe)->not->toContain('text/html');
})->group('fast');

it('returns null for null and for a stylesheet that is entirely hostile', function (): void {
    // "No custom CSS" must have one representation in the column, or every
    // reader needs to test for two.
    expect(CssSanitizer::sanitize(null))->toBeNull()
        ->and(CssSanitizer::sanitize('   '))->toBeNull()
        ->and(CssSanitizer::sanitize('@import url(https://evil.example/x.css);'))->toBeNull();
})->group('fast');

it('is idempotent, because it runs on read as well as on write', function (): void {
    // §2.2 sanitises on the way out too, so this runs on every render of every
    // hosted page. A pass that kept changing its own output would mean the CSS
    // an operator sees depends on how many times it has been read.
    $css = 'a{background:url(javascript:alert(1));color:red}.b{scroll-behavior:smooth}';

    $once = CssSanitizer::sanitize($css);
    $twice = CssSanitizer::sanitize($once);

    expect($twice)->toBe($once);
})->group('fast');
