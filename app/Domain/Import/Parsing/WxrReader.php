<?php

declare(strict_types=1);

namespace App\Domain\Import\Parsing;

use App\Domain\Import\Exceptions\ImportFileUnreadable;
use SimpleXMLElement;

/**
 * Reads a WordPress export (WXR 1.2) for the three things SAA-13 maps from it:
 * WooCommerce product categories, YITH Booking person types, and products.
 *
 * ## What is read, and from where
 *
 * - **Categories** are `<wp:term>` entries in the `product_cat` taxonomy.
 * - **Person types** are posts of YITH's own post type — `ywbk-person-type`,
 *   and `yith_booking_person_type` for older installs — whose title is the
 *   name the operator gave them («Ενήλικας», «Παιδί»).
 * - **Products** are posts of type `product`. A product is a YITH booking
 *   product when its `product_type` term is `booking`; anything else (a cap
 *   sold in the same shop) is read too, so the review screen can say it was
 *   seen and why it is being left out.
 *
 * YITH stores its settings in post meta, several of them PHP-serialised arrays.
 * They are unserialised with `allowed_classes => false`, so a crafted export can
 * produce arrays and strings and never an object.
 *
 * ## The YITH meta keys are the ones YITH Booking writes, as far as they could
 * be verified without a live site
 *
 * `_yith_booking_duration`, `_yith_booking_duration_unit`,
 * `_yith_booking_max_persons`, `_yith_booking_base_cost`,
 * `_yith_booking_enable_person_types` and `_yith_booking_person_types`. The
 * first run against a real export may find a key renamed between plugin
 * versions; every value read here has a fallback, and a product that falls
 * back says so in its row.
 *
 * SimpleXML with `LIBXML_NONET`: an export is a file the operator downloaded
 * from their own site, but nothing in it gets to make this server fetch a URL.
 */
final class WxrReader
{
    private const PERSON_TYPE_POST_TYPES = ['ywbk-person-type', 'yith_booking_person_type'];

    /**
     * @return array{
     *     categories: list<array{id: string, slug: string, name: string}>,
     *     people_types: list<array{id: string, title: string}>,
     *     products: list<array<string, mixed>>
     * }
     *
     * @throws ImportFileUnreadable
     */
    public function read(string $path): array
    {
        $contents = @file_get_contents($path);

        if ($contents === false || trim($contents) === '') {
            throw ImportFileUnreadable::wxr();
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($contents, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $xml instanceof SimpleXMLElement || ! isset($xml->channel)) {
            throw ImportFileUnreadable::wxr();
        }

        $channel = $xml->channel;
        $categories = $this->categories($channel);
        $bySlug = [];

        foreach ($categories as $category) {
            $bySlug[$category['slug']] = $category['id'];
        }

        $peopleTypes = [];
        $products = [];

        foreach ($channel->item as $item) {
            $wp = $item->children('wp', true);
            $postType = (string) $wp->post_type;

            if (in_array($postType, self::PERSON_TYPE_POST_TYPES, true)) {
                $peopleTypes[] = ['id' => (string) $wp->post_id, 'title' => trim((string) $item->title)];

                continue;
            }

            if ($postType === 'product') {
                $products[] = $this->product($item, $bySlug);
            }
        }

        return ['categories' => $categories, 'people_types' => $peopleTypes, 'products' => $products];
    }

    /** @return list<array{id: string, slug: string, name: string}> */
    private function categories(SimpleXMLElement $channel): array
    {
        $out = [];

        foreach ($channel->children('wp', true)->term as $term) {
            if ((string) $term->term_taxonomy !== 'product_cat') {
                continue;
            }

            $out[] = [
                'id' => (string) $term->term_id,
                'slug' => (string) $term->term_slug,
                'name' => trim((string) $term->term_name),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $categoryIdsBySlug
     * @return array<string, mixed>
     */
    private function product(SimpleXMLElement $item, array $categoryIdsBySlug): array
    {
        $wp = $item->children('wp', true);
        $meta = [];

        foreach ($wp->postmeta as $row) {
            $meta[(string) $row->meta_key] = $this->metaValue((string) $row->meta_value);
        }

        $categoryIds = [];
        $productType = null;

        foreach ($item->category as $category) {
            $domain = (string) $category['domain'];
            $nicename = (string) $category['nicename'];

            if ($domain === 'product_type') {
                $productType = $nicename;
            } elseif ($domain === 'product_cat' && isset($categoryIdsBySlug[$nicename])) {
                $categoryIds[] = $categoryIdsBySlug[$nicename];
            }
        }

        $isBooking = $productType === 'booking' || array_key_exists('_yith_booking_duration', $meta);

        return [
            'id' => (string) $wp->post_id,
            'title' => trim((string) $item->title),
            'status' => (string) $wp->status,
            'summary' => $this->plain((string) $item->children('excerpt', true)->encoded),
            'description' => $this->plain((string) $item->children('content', true)->encoded),
            'product_type' => $productType,
            'is_booking' => $isBooking,
            'category_ids' => $categoryIds,
            'price' => $this->string($meta['_price'] ?? $meta['_regular_price'] ?? null),
            'base_cost' => $this->string($meta['_yith_booking_base_cost'] ?? null),
            'duration' => $this->int($meta['_yith_booking_duration'] ?? null),
            'duration_unit' => $this->string($meta['_yith_booking_duration_unit'] ?? null),
            'max_persons' => $this->int($meta['_yith_booking_max_persons'] ?? null),
            'person_types' => $this->personTypes($meta),
        ];
    }

    /**
     * The person types this product is sold with, as `type id => base cost`.
     *
     * YITH stores them as a list of `{id, base_cost, block_cost, …}` arrays;
     * older versions keyed the same arrays by type id. Both are accepted.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, string|null>
     */
    private function personTypes(array $meta): array
    {
        $enabled = $meta['_yith_booking_enable_person_types'] ?? null;
        $types = $meta['_yith_booking_person_types'] ?? null;

        if (! is_array($types) || $enabled === 'no') {
            return [];
        }

        $out = [];

        foreach ($types as $key => $type) {
            if (! is_array($type)) {
                continue;
            }

            $id = $this->string($type['id'] ?? null) ?? (is_int($key) ? null : (string) $key);

            if ($id === null || $id === '') {
                continue;
            }

            $out[$id] = $this->string($type['base_cost'] ?? null);
        }

        return $out;
    }

    private function metaValue(string $raw): mixed
    {
        $raw = trim($raw);

        if (preg_match('/^(a|s|i|b|d):/', $raw) === 1) {
            $value = @unserialize($raw, ['allowed_classes' => false]);

            if ($value !== false || $raw === 'b:0;') {
                return $value;
            }
        }

        return $raw;
    }

    /** HTML from WordPress, as plain paragraphs — Kaiki's description is sanitised text, not a page builder's markup. */
    private function plain(string $html): ?string
    {
        $text = preg_replace('/<\s*(br|\/p|\/li|\/h[1-6])\s*\/?>/i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\n{3,}/", "\n\n", trim($text)) ?? $text;

        return $text === '' ? null : $text;
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
