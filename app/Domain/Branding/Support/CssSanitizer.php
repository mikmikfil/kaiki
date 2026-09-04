<?php

declare(strict_types=1);

namespace App\Domain\Branding\Support;

/**
 * Makes operator-supplied CSS safe to embed in a hosted page (spec BRD-2, HOS-8).
 *
 * ## Why this is not a CSS parser
 *
 * A full parser would let us allow-list properties, which is the stronger
 * design. It is also a dependency, a spec to track, and a new way for valid
 * CSS to stop working — and the threat here is narrow and well known: the
 * operator's stylesheet is rendered inside a `<style>` block on a page that
 * takes payments. What must not survive is anything that can **execute** or
 * **fetch**. Everything else is the operator's problem and their own page.
 *
 * So this removes constructs, and `CssSanitizerTest` is a table of hostile
 * inputs rather than a claim of completeness.
 *
 * ## The order matters more than the patterns
 *
 * CSS escapes are decoded **first**. `\6a avascript:` and `java\000073cript:`
 * are the same string to a browser and different strings to a regex, so a
 * sanitiser that pattern-matches before decoding rejects the obvious spelling
 * and passes the one an attacker would actually use. Decoding first means every
 * later rule sees what the browser will see.
 *
 * ## Sanitised on read as well as write
 *
 * `docs/data-model.md` §2.2 stores `custom_css` raw and calls this on the way
 * out too, so tightening a rule here protects every row that already exists
 * instead of needing a data migration over operators' stylesheets. That is why
 * this class is pure and static: it has to be safe to call on every read.
 */
final class CssSanitizer
{
    /**
     * Anything that executes, fetches, or escapes the `<style>` element.
     *
     * Regex fragments rather than plain substrings, and that is not styling:
     * `behavior` as a substring also matches **`scroll-behavior: smooth`**,
     * which is ordinary modern CSS, and a sanitiser that deletes an operator's
     * smooth scrolling is one they will ask to have switched off. Each entry
     * therefore pins the token to a property or function position.
     *
     * Kept as one list with a reason each, because the next person's question
     * is always "can I delete this one".
     *
     * @var array<string, string>
     */
    private const DANGEROUS = [
        // url(javascript:…) — direct execution in engines that still allow it.
        'javascript\s*:' => 'javascript URL',

        // The same, for the IE-era engines still shipped inside kiosk browsers.
        'vbscript\s*:' => 'vbscript URL',

        // IE dynamic properties: arbitrary JavaScript inside a declaration.
        'expression\s*\(' => 'IE expression()',

        // IE .htc binding — remote code by stylesheet. Pinned with a lookbehind
        // so `scroll-behavior` and `overscroll-behavior` survive untouched.
        '(?<![-\w])behavior\s*:' => 'IE behavior binding',

        // XBL binding, the Gecko equivalent.
        '-moz-binding\s*:' => 'XBL binding',

        // A document, not an image, in a url().
        'data\s*:\s*text/html' => 'HTML document in a url()',
    ];

    /** Returns CSS with everything executable or fetching removed. */
    public static function sanitize(?string $css): ?string
    {
        if ($css === null) {
            return null;
        }

        $css = self::decodeEscapes($css);

        // Null bytes and control characters split a token for a regex and not
        // for a browser: `java\0script:` is the whole trick in one byte.
        $css = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $css);

        // Comments go before anything else is matched, because `java/**/script:`
        // is a token to the browser only after they are gone.
        $css = (string) preg_replace('!/\*.*?\*/!su', '', $css);

        // CSS has no use for angle brackets, and `</style>` is the shortest
        // path from a stylesheet to a script tag. Removed rather than encoded:
        // there is nothing to preserve.
        $css = (string) preg_replace('/[<>]/u', '', $css);

        // At-rules that make the page fetch something. `@import` is a request to
        // a third party from the operator's checkout page — a GDPR problem even
        // when the file is harmless CSS. `@charset` can change how everything
        // after it is decoded, which re-opens every rule above.
        $css = (string) preg_replace('/@(import|charset|namespace)\b[^;{}]*;?/iu', '', $css);

        // `url(...)` first, and as a whole group, because a URL is the one
        // place a `;` legitimately appears *inside* a declaration:
        // `url(data:text/html;base64,…)` splits in half under a rule that treats
        // `;` as a boundary, and the half after it survives as debris. Matching
        // the group — with one level of nesting, which is all `url()` ever has —
        // takes the entire thing.
        $css = (string) preg_replace_callback(
            '~url\s*\((?:[^()]|\([^()]*\))*\)~iu',
            static function (array $matches): string {
                foreach (array_keys(self::DANGEROUS) as $fragment) {
                    if (preg_match('~' . $fragment . '~iu', $matches[0]) === 1) {
                        return '';
                    }
                }

                return $matches[0];
            },
            $css,
        );

        // Whole declarations, not just the dangerous token: leaving
        // `background: url()` behind after cutting `javascript:` out of it is a
        // rule that no longer says what the operator wrote, and the empty
        // `url()` is its own small surprise. This is also what catches a
        // dangerous *property* — `behavior: url(x.htc)` hides nothing in the
        // URL at all.
        foreach (array_keys(self::DANGEROUS) as $fragment) {
            $css = (string) preg_replace('~[^;{}]*' . $fragment . '[^;{}]*;?~iu', '', $css);
        }

        // A last pass for a dangerous token that reached here outside any
        // declaration — inside an at-rule prelude, or in text left behind by
        // the rules above. Belt and braces, and cheap.
        foreach (array_keys(self::DANGEROUS) as $fragment) {
            $css = (string) preg_replace('~' . $fragment . '~iu', '', $css);
        }

        // A property left holding nothing, because its only value was the
        // `url()` removed above. Inert either way; removed so that what an
        // operator reads back is CSS rather than the wreckage of their CSS.
        $css = (string) preg_replace('~[a-zA-Z-]+\s*:\s*(?=[;}])~u', '', $css);
        $css = (string) preg_replace('~;\s*(?=[;}])~u', '', $css);

        $css = trim($css);

        // An operator who clears the box gets null back rather than an empty
        // string, so "no custom CSS" has one representation in the column.
        return $css === '' ? null : $css;
    }

    /**
     * Resolves CSS escape sequences to the characters a browser would see.
     *
     * Two forms, both from the CSS syntax spec: a backslash followed by one to
     * six hex digits and an optional single whitespace terminator, and a
     * backslash followed by any other single character.
     */
    private static function decodeEscapes(string $css): string
    {
        $css = (string) preg_replace_callback(
            '/\\\\([0-9a-fA-F]{1,6})[ \t\n\r\f]?/u',
            static function (array $matches): string {
                $codepoint = (int) hexdec($matches[1]);

                // Null and anything outside Unicode become the replacement
                // character, which is what a browser does — and, unlike an empty
                // string, cannot join two halves of a keyword together.
                if ($codepoint === 0 || $codepoint > 0x10FFFF) {
                    return "\u{FFFD}";
                }

                return mb_chr($codepoint, 'UTF-8') ?: "\u{FFFD}";
            },
            $css,
        );

        // `\j` is just `j`. This is the escape that makes `\java\script:` work.
        return (string) preg_replace('/\\\\(.)/su', '$1', $css);
    }
}
