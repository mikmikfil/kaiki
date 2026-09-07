<?php

declare(strict_types=1);

namespace App\Domain\Hosted\Support;

use App\Http\Middleware\HostedPageHeaders;
use Illuminate\Support\HtmlString;

/**
 * Encodes a structured-data document for a `<script type="application/ld+json">`.
 *
 * ## One encoder, because the escaping is one decision
 *
 * #103 introduced this reasoning for the `FAQPage` block and #104 needed the
 * identical thing for `Product` and `Event`; two encoders would be two chances
 * to get the flags wrong, and the failure mode is silent in both directions —
 * a malformed document is discarded by search engines without a word, and an
 * under-escaped one lets an operator's text close the element.
 *
 * ## The four `JSON_HEX_*` flags are the whole point
 *
 * They turn `<`, `>`, `&`, `'` and `"` into `\uXXXX` escapes. Those are valid
 * JSON, mean exactly the characters they replace, and **cannot close the script
 * element** — so an operator who pastes `</script>` into a description gets
 * their own text back rather than a broken page.
 *
 * `JSON_UNESCAPED_UNICODE` stays on: Greek is the language most of this content
 * is written in, and rendering every letter as an escape would treble the size
 * of the block on every page for no benefit.
 *
 * ## It returns an `HtmlString`, so Blade renders it with `{{ }}`
 *
 * `{!! !!}` in a template is a thing a reviewer has to check the provenance of
 * every time. The hosted views have none, and this is what keeps that true for
 * the two places that emit JSON.
 *
 * ## The `<script>` still needs the nonce
 *
 * HOS-8's policy has no `unsafe-inline`, and a browser applies `script-src` to
 * a script element whatever its `type`. An un-nonced block is dropped unread,
 * which is the same outcome as not writing one — so every caller carries the
 * per-response nonce from {@see HostedPageHeaders}.
 */
final class JsonLd
{
    /**
     * The document as embeddable JSON, or null when there is nothing to say.
     *
     * Null rather than `{}`: an empty document is a structured-data error
     * rather than an empty section, and the caller renders no element at all.
     *
     * @param  array<string, mixed>  $document
     */
    public static function encode(array $document): ?HtmlString
    {
        if ($document === []) {
            return null;
        }

        $json = json_encode(
            $document,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
        );

        return $json === false ? null : new HtmlString($json);
    }

    /**
     * A value fit for a JSON-LD string field: trimmed, or absent.
     *
     * Search engines treat an empty string as a value and complain about it,
     * while an absent optional property is simply not there. So a caller builds
     * its node with `...self::when('description', $text)` rather than deciding
     * per field whether null or `''` is the safer of two wrong answers.
     *
     * @return array<string, string>
     */
    public static function when(string $key, ?string $value): array
    {
        $value = trim((string) $value);

        return $value === '' ? [] : [$key => $value];
    }
}
