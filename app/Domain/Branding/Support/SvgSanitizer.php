<?php

declare(strict_types=1);

namespace App\Domain\Branding\Support;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Makes an operator-supplied SVG safe to serve (spec BRD-7, SEC-13).
 *
 * ## Why an SVG is not an image
 *
 * PNG and WebP are pixels and a decoder. **An SVG is a document**: it can carry
 * `<script>`, event-handler attributes, CSS, and references to other documents,
 * and a browser will run all of it when the file is opened directly or inlined.
 * A logo upload is therefore the one place in this application where an
 * operator hands us markup, and it gets treated as markup.
 *
 * ## Allow-list for elements, rules for the three attributes that matter
 *
 * Elements are allow-listed: anything not named in {@see self::ELEMENTS} is
 * removed with its subtree. That is the opposite posture to {@see CssSanitizer},
 * and deliberately — SVG's dangerous surface is open-ended (`foreignObject`
 * holds arbitrary HTML, `set` can assign a `javascript:` value to an attribute
 * after load), while its useful surface for a *logo* is about thirty shapes.
 *
 * Attributes get three rules rather than a list, because SVG's presentation
 * attributes number in the hundreds and an allow-list of those would reject
 * ordinary logos every week:
 *
 * 1. **`on*` is removed.** Every event handler, no exceptions.
 * 2. **`href` / `xlink:href` must be a same-document fragment** (`#gradient-1`).
 *    A `use` pointing at another file is a request from whoever opens the logo;
 *    a `javascript:` href is worse.
 * 3. **`style` runs through {@see CssSanitizer}**, the same code that guards the
 *    hosted page, so `url(javascript:...)` in a fill cannot survive here and die
 *    there.
 *
 * ## The parse itself is part of the threat
 *
 * A DOCTYPE is refused outright rather than stripped, and the external entity
 * loader is disabled for the duration of the parse. Billion-laughs and XXE both
 * happen *while the document is being read*, so a sanitiser that parses first
 * and inspects afterwards has already lost.
 */
final class SvgSanitizer
{
    /**
     * Everything a logo legitimately needs.
     *
     * `foreignObject` is absent because it holds arbitrary HTML. `image` is
     * absent because its `href` is a second document — including, cheerfully,
     * another SVG. The animation elements (`animate`, `set`, `animateTransform`)
     * are absent because they can assign an attribute value *after* this class
     * has finished inspecting the attributes.
     *
     * @var list<string>
     */
    private const ELEMENTS = [
        'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'metadata', 'style',
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan', 'textPath',
        'linearGradient', 'radialGradient', 'stop',
        'clipPath', 'mask', 'pattern', 'marker',
        'filter', 'feGaussianBlur', 'feOffset', 'feBlend', 'feColorMatrix',
        'feComposite', 'feFlood', 'feMerge', 'feMergeNode', 'feMorphology',
    ];

    /**
     * Returns the sanitised SVG, or **null** when the file is not usable as one.
     *
     * Null rather than an empty document, so the caller refuses the upload with
     * a message instead of storing a blank logo the operator will not notice
     * until a guest sees the booking page.
     */
    public static function sanitize(string $svg): ?string
    {
        // A DOCTYPE is the entry point for both entity expansion and external
        // entity resolution. There is no legitimate one in a logo, so this is a
        // refusal and not a strip — a file carrying one is not a file we want.
        if (preg_match('/<!DOCTYPE/i', $svg) === 1) {
            return null;
        }

        $document = new DOMDocument;
        $document->preserveWhiteSpace = false;

        // Disabled for the duration of the parse and restored afterwards, so
        // that nothing this application does later inherits the change.
        //
        // The previous loader comes from `libxml_get_external_entity_loader()`
        // and **not** from the return of the setter. The setter returns
        // `bool(true)`, not the callable it replaced — so the obvious
        // `$previous = libxml_set_external_entity_loader(...)` restores `true`,
        // which is a TypeError thrown from a `finally` block, on the way out of
        // a sanitiser, for every SVG. The getter is PHP 8.4, which this project
        // requires (ADR-0014).
        $previousLoader = libxml_get_external_entity_loader();
        libxml_set_external_entity_loader(static fn (): null => null);
        $previousErrors = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML($svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
            libxml_set_external_entity_loader($previousLoader);
        }

        if ($loaded === false || ! $document->documentElement instanceof DOMElement) {
            return null;
        }

        if (strtolower($document->documentElement->localName) !== 'svg') {
            return null;
        }

        self::clean($document->documentElement);

        $output = $document->saveXML($document->documentElement);

        return $output === false ? null : $output;
    }

    /** Depth-first, over a snapshot of each list because removal mutates the live one. */
    private static function clean(DOMElement $element): void
    {
        // No null check on `attributes`: it is nullable on `DOMNode` and always
        // present on a `DOMElement`, which is the only thing this walks.
        foreach (iterator_to_array($element->attributes) as $attribute) {
            if ($attribute instanceof DOMAttr) {
                self::cleanAttribute($element, $attribute);
            }
        }

        foreach (iterator_to_array($element->childNodes) as $child) {
            self::cleanChild($element, $child);
        }
    }

    private static function cleanChild(DOMElement $parent, DOMNode $child): void
    {
        if ($child instanceof DOMElement) {
            if (! in_array($child->localName, self::ELEMENTS, true)) {
                $parent->removeChild($child);

                return;
            }

            // A style block inside an SVG is CSS on a page the operator does not
            // control, so it gets the same treatment as `custom_css`.
            if (strtolower($child->localName) === 'style') {
                $child->textContent = CssSanitizer::sanitize($child->textContent) ?? '';
            }

            self::clean($child);

            return;
        }

        // Comments and processing instructions are removed: a PI is executable
        // in some viewers, and a comment is where a payload waits for the next
        // tool that strips comments badly. Text stays.
        if (! $child instanceof DOMText) {
            $parent->removeChild($child);
        }
    }

    private static function cleanAttribute(DOMElement $element, DOMAttr $attribute): void
    {
        $name = strtolower($attribute->localName);

        // Rule 1 — every event handler, matched on the *local* name so that a
        // namespaced `x:onload` is caught too.
        if (str_starts_with($name, 'on')) {
            $element->removeAttributeNode($attribute);

            return;
        }

        // Rule 2 — same-document fragments only. Matched on the local name so
        // that `href` and `xlink:href` are one rule rather than two that can
        // drift apart.
        if ($name === 'href') {
            if (! str_starts_with(trim($attribute->value), '#')) {
                $element->removeAttributeNode($attribute);
            }

            return;
        }

        // Rule 3 — inline CSS goes through the CSS sanitiser. It is a
        // declaration list rather than a stylesheet, so it is wrapped in a
        // throwaway rule to give the sanitiser the shape it expects, and
        // unwrapped again afterwards.
        if ($name === 'style') {
            $sanitized = CssSanitizer::sanitize('a{' . $attribute->value . '}');

            $declarations = $sanitized === null
                ? ''
                : trim((string) preg_replace('/^a\{|\}$/u', '', $sanitized));

            if ($declarations === '') {
                $element->removeAttributeNode($attribute);

                return;
            }

            $attribute->value = $declarations;
        }
    }
}
