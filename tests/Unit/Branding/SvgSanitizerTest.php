<?php

declare(strict_types=1);

use App\Domain\Branding\Support\SvgSanitizer;

/*
|--------------------------------------------------------------------------
| Operator SVG — spec BRD-7, SEC-13
|--------------------------------------------------------------------------
|
| A PNG is pixels and a decoder. **An SVG is a document**: it can carry
| `<script>`, event handlers, CSS and references to other documents, and a
| browser runs all of it when the file is opened directly or inlined. A logo
| upload is the one place in this application where an operator hands us markup.
|
| Elements are allow-listed and attributes get three rules. The table below is
| what those rules have been shown; like the CSS table it is evidence, not a
| completeness claim.
|
*/

// Not `svg()`: blade-icons declares a global helper of that name, and a
// redeclaration is a fatal error before a single test runs.
function logoSvg(string $body, string $attributes = ''): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" ' . $attributes . '>' . $body . '</svg>';
}

dataset('hostile svg', [
    'script element' => [fn (): string => logoSvg('<script>alert(1)</script><path d="M0 0"/>'), 'script'],
    'foreignObject with HTML' => [fn (): string => logoSvg('<foreignObject><body xmlns="http://www.w3.org/1999/xhtml">x</body></foreignObject>'), 'foreignObject'],
    'onload on the root' => [fn (): string => logoSvg('<path d="M0 0"/>', 'onload="alert(1)"'), 'onload'],
    'onclick on a shape' => [fn (): string => logoSvg('<path d="M0 0" onclick="alert(1)"/>'), 'onclick'],
    'animate assigning after load' => [fn (): string => logoSvg('<set attributeName="href" to="javascript:alert(1)"/>'), 'javascript'],
    'external image reference' => [fn (): string => logoSvg('<image href="https://evil.example/x.png"/>'), 'evil.example'],
    'use pointing at another document' => [fn (): string => logoSvg('<use href="https://evil.example/x.svg#a"/>'), 'evil.example'],
    'javascript href' => [fn (): string => logoSvg('<use href="javascript:alert(1)"/>'), 'javascript'],
    'javascript in an inline style' => [fn (): string => logoSvg('<path d="M0 0" style="background:url(javascript:alert(1))"/>'), 'javascript'],
    'javascript in a style block' => [fn (): string => logoSvg('<style>path{background:url(javascript:alert(1))}</style><path d="M0 0"/>'), 'javascript'],
    'processing instruction' => [fn (): string => '<?xml-stylesheet href="evil.xsl"?>' . logoSvg('<path d="M0 0"/>'), 'xml-stylesheet'],
]);

it('strips every scripting and fetching construct from an SVG', function (Closure $svg, string $forbidden): void {
    $safe = SvgSanitizer::sanitize($svg());

    expect(strtolower((string) $safe))->not->toContain(strtolower($forbidden));
})->with('hostile svg')->group('fast');

it('refuses a document carrying a DOCTYPE rather than stripping it', function (): void {
    // Billion-laughs and XXE both happen **while the document is being read**,
    // so a sanitiser that parses first and inspects afterwards has already
    // lost. There is no legitimate DOCTYPE in a logo, so this is a refusal.
    $xxe = <<<'XML'
    <!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>
    <svg xmlns="http://www.w3.org/2000/svg"><text>&xxe;</text></svg>
    XML;

    expect(SvgSanitizer::sanitize($xxe))->toBeNull();
})->group('fast');

it('refuses a file whose root element is not svg', function (): void {
    // Null rather than an empty document, so the caller refuses the upload with
    // a message instead of storing a blank logo nobody notices until a guest
    // sees the booking page.
    expect(SvgSanitizer::sanitize('<html><body>hello</body></html>'))->toBeNull()
        ->and(SvgSanitizer::sanitize('not xml at all'))->toBeNull()
        ->and(SvgSanitizer::sanitize('<svg xmlns="http://www.w3.org/2000/svg"><path'))->toBeNull();
})->group('fast');

it('keeps a real logo intact', function (): void {
    // The half that fails quietly. An operator whose logo comes back as an
    // empty box does not read the release notes to find out why.
    $logo = logoSvg(
        '<title>Aegean Blue</title>'
        . '<defs><linearGradient id="sea"><stop offset="0" stop-color="#0F62FE"/></linearGradient></defs>'
        . '<path d="M0 0 L10 10 Z" fill="url(#sea)" stroke="#0B3D91" stroke-width="0.5"/>'
        . '<text x="1" y="9" font-family="Inter" fill="#101828">Αιγαίο</text>'
    );

    $safe = (string) SvgSanitizer::sanitize($logo);

    expect($safe)
        ->toContain('<path')
        ->toContain('fill="url(#sea)"')
        ->toContain('linearGradient')
        ->toContain('Αιγαίο')
        ->toContain('stroke-width="0.5"');
})->group('fast');

it('keeps a same-document fragment href, which is how a gradient is referenced', function (): void {
    // Rule 2 is "same-document fragments only", not "no href". A `use` pointing
    // at `#logo-mark` is how half the logos in the world are built, and
    // stripping it would break them while blocking nothing.
    $safe = (string) SvgSanitizer::sanitize(logoSvg('<defs><path id="m" d="M0 0"/></defs><use href="#m"/>'));

    expect($safe)->toContain('href="#m"');
})->group('fast');

it('keeps the safe half of an inline style and drops the rest', function (): void {
    // The style attribute goes through CssSanitizer — the same code that guards
    // the hosted page — so a construct cannot survive here and die there.
    $safe = (string) SvgSanitizer::sanitize(
        logoSvg('<path d="M0 0" style="fill:#0F62FE;background:url(javascript:alert(1))"/>'),
    );

    expect($safe)->toContain('fill:#0F62FE');
    expect((string) $safe)->not->toContain('javascript');
})->group('fast');

it('leaves the libxml entity loader as it found it', function (): void {
    // The loader is disabled for the duration of the parse. If it were not
    // restored, every later XML parse in the process — an iCal import, a
    // gateway response — would silently inherit the change.
    //
    // This is read with the **getter**, because the setter returns `bool(true)`
    // rather than the callable it replaced. Restoring the setter's return value
    // is the bug this test found on its first run: it threw a TypeError out of
    // a `finally` block for every SVG the sanitiser touched.
    $sentinel = static fn (): null => null;
    libxml_set_external_entity_loader($sentinel);

    SvgSanitizer::sanitize(logoSvg('<path d="M0 0"/>'));

    expect(libxml_get_external_entity_loader())->toBe($sentinel);

    libxml_set_external_entity_loader(null);
})->group('fast');
