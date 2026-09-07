<?php

declare(strict_types=1);

namespace App\Domain\Hosted\Support;

use App\Http\Middleware\HostedPageHeaders;
use App\Models\Faq;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * `FAQPage` structured data for a rendered FAQ section (HOS-2's sibling).
 *
 * HOS-2 asks for `Product` and `Event` on the product pages; this is the third
 * one, and the only one whose whole purpose is to put the operator's answers
 * into the search result itself rather than into a link to it. An operator whose
 * "what happens if it rains" is answered on Google is an operator taking one
 * fewer telephone call.
 *
 * ## Encoded through {@see JsonLd}, and never with `{!! !!}`
 *
 * A JSON-LD block sits inside a `<script>` element, where Blade's `{{ }}`
 * escaping would corrupt the JSON and `{!! !!}` would print operator text into
 * a script element unescaped. Both are wrong, so neither is used — the shared
 * encoder returns an {@see HtmlString}, and it owns the escaping decision for
 * this block and for #104's `Product` and `Event` graph alike.
 *
 * ## The `<script>` needs the nonce
 *
 * HOS-8's policy has no `unsafe-inline`, and browsers apply `script-src` to a
 * `<script>` element whatever its `type` — an un-nonced JSON-LD block is dropped
 * by the browser without a word, which is the same outcome as not writing one.
 * {@see HostedPageHeaders} mints the nonce; the partial
 * carries it.
 */
final class FaqSchema
{
    /**
     * The `FAQPage` document for these entries, or null when there are none.
     *
     * Null rather than an empty `mainEntity`, because a `FAQPage` with no
     * questions is a structured-data error rather than an empty section — and
     * the section is not rendered either, so there is nothing to describe.
     *
     * @param  Collection<int, Faq>  $entries
     */
    public static function json(Collection $entries): ?HtmlString
    {
        if ($entries->isEmpty()) {
            return null;
        }

        $document = [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $entries->map(static fn (Faq $faq): array => [
                '@type' => 'Question',
                // The resolved translations, so the block is in the language of
                // the page it is on. A Greek page describing itself to Google in
                // English is a page that ranks for the wrong query.
                'name' => BlockText::line((string) $faq->question, 300),
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    // Plain text, not the rendered paragraphs. Google accepts
                    // limited HTML here, and passing markup would mean the
                    // escaping rules of two consumers instead of one.
                    'text' => self::plainText((string) $faq->answer),
                ],
            ])->values()->all(),
        ];

        return JsonLd::encode($document);
    }

    /**
     * The answer as one block of plain text, with its paragraphs preserved.
     *
     * Newlines survive — they are meaningful to a reader and harmless in JSON,
     * where the encoder turns them into `\n`. What does not survive is the
     * carriage return of a Windows text area, for the same reason
     * {@see BlockText} drops it: two encodings of the same break in one string.
     */
    private static function plainText(string $answer): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $answer));
    }
}
