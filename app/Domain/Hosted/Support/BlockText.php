<?php

declare(strict_types=1);

namespace App\Domain\Hosted\Support;

use Illuminate\Support\HtmlString;

/**
 * The only thing in the product allowed to turn an operator's prose into markup.
 *
 * ## The whole rule, in one method
 *
 * Escape **first**, then add structure. Doing it the other way round — splitting
 * into paragraphs, wrapping each in a `<p>`, escaping the result — escapes the
 * tags this class just added, and the fix somebody reaches for at that point is
 * to stop escaping. That is how a rich-text field arrives without anybody
 * deciding to add one.
 *
 * So: `e()` runs over the raw string, and the only characters that become
 * markup afterwards are the newlines this class put meaning on itself. There is
 * no allow-list of "safe" tags, because an allow-list is a thing that grows.
 *
 * ## Why this is not just `nl2br(e($text))`
 *
 * `nl2br` gives a `<br>` for every newline, so a paragraph break and a wrapped
 * line look identical and the page has no paragraphs at all — which is the
 * single most common complaint about text areas that render this way. A blank
 * line starts a paragraph; a single newline inside one is a line break. That is
 * what a person typing into a box means, and it is what they get.
 *
 * ## Why not Markdown
 *
 * Markdown is an allow-list with better marketing: every renderer has a raw-HTML
 * passthrough, most have it on by default, and the ones that do not still have
 * link syntax pointing anywhere. HOS-8 keeps the page's script surface at zero
 * and this keeps its markup surface there too. An operator who needs a link puts
 * it in a block that has a link field.
 */
final class BlockText
{
    /**
     * Escaped prose, with blank lines as paragraphs and single newlines as breaks.
     *
     * Returns an {@see HtmlString} so Blade renders it with `{{ }}` rather than
     * `{!! !!}`. That is deliberate: `{!! !!}` in a template is a thing a
     * reviewer has to check the provenance of every time, and there should be
     * exactly one place in the hosted views where the question can even arise.
     */
    public static function paragraphs(?string $text): HtmlString
    {
        $normalised = str_replace(["\r\n", "\r"], "\n", trim((string) $text));

        if ($normalised === '') {
            return new HtmlString('');
        }

        $html = '';

        foreach (preg_split('/\n{2,}/', $normalised) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            // Escape, then join the surviving single newlines with `<br>`. The
            // order is the point of the class.
            $lines = array_map(static fn (string $line): string => e(trim($line)), explode("\n", $paragraph));

            $html .= '<p>' . implode('<br>', $lines) . '</p>';
        }

        return new HtmlString($html);
    }

    /**
     * Escaped prose as one line, for a meta description or an email preheader.
     *
     * Truncated on a word boundary, because a `meta description` cut mid-word is
     * what search engines show. Returns a plain string rather than an
     * `HtmlString` — an attribute value is escaped by Blade at the point of use
     * and double-escaping it here would print `&amp;` in a snippet.
     */
    public static function line(?string $text, int $limit = 160): string
    {
        $collapsed = trim((string) preg_replace('/\s+/u', ' ', (string) $text));

        if ($collapsed === '' || mb_strlen($collapsed) <= $limit) {
            return $collapsed;
        }

        $cut = mb_substr($collapsed, 0, $limit);
        $lastSpace = mb_strrpos($cut, ' ');

        return rtrim($lastSpace === false ? $cut : mb_substr($cut, 0, $lastSpace), ' ,.;:·—-') . '…';
    }
}
