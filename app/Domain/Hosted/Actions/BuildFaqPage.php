<?php

declare(strict_types=1);

namespace App\Domain\Hosted\Actions;

use App\Models\FaqEntry;
use Illuminate\Support\Collection;

/**
 * The FAQ page: the operator's general questions, then one group per trip.
 *
 * ## Why the page carries both
 *
 * An operator writing "what should I bring on the sunset cruise?" has written
 * an FAQ entry, not a product description, and a page that showed only the
 * general ones would hide half of what they wrote with no way to find it until
 * #104's product pages ship. Grouping is the honest presentation: a visitor
 * scanning for their trip finds its questions together, and a visitor with a
 * business question finds those at the top where they are looking.
 *
 * ## The structured data names only what is on the page
 *
 * HOS-2 wants `FAQPage` markup, and the one rule Google enforces about it is
 * that every question in the JSON-LD is **visible on the page**. Hidden
 * entries, entries belonging to another operator, entries for an inactive
 * product — each of those is a manual action against the operator's own domain,
 * not a warning. So the markup is built from the same collection the template
 * renders, in the same method, rather than from a second query that could
 * disagree with it.
 */
class BuildFaqPage
{
    /**
     * The page: general entries first, then one group per product.
     *
     * @return array{general: Collection<int, FaqEntry>, byProduct: Collection<int, array{title: string, entries: Collection<int, FaqEntry>}>, all: Collection<int, FaqEntry>}
     */
    public function __invoke(): array
    {
        $entries = FaqEntry::query()->forPage()->with('product')->get();

        $general = $entries->whereNull('product_id')->values();

        $byProduct = $entries
            // An entry whose product was deleted or deactivated keeps its row —
            // the relation is what decides whether it renders, so a soft-deleted
            // product takes its questions off the page without taking them off
            // the operator's screen.
            ->filter(fn (FaqEntry $entry): bool => $entry->product !== null)
            ->groupBy('product_id')
            ->map(fn (Collection $group): array => [
                'title' => (string) $group->first()?->product?->title,
                'entries' => $group->values(),
            ])
            ->values();

        // What the JSON-LD may name: exactly what the template will render.
        $all = $general->concat(
            $byProduct->flatMap(static fn (array $group): Collection => $group['entries'])
        )->values();

        return ['general' => $general, 'byProduct' => $byProduct, 'all' => $all];
    }

    /**
     * `FAQPage` structured data for the entries on the page.
     *
     * Encoded here rather than in the template so the escaping is decided once.
     * `JSON_UNESCAPED_UNICODE` matters more than it looks: without it every
     * Greek character becomes a `\uXXXX` escape, which is valid JSON, five
     * times the bytes, and unreadable in the one place an operator might look
     * to check their own markup.
     *
     * Returns null when there is nothing to describe. An empty `FAQPage` with a
     * zero-length `mainEntity` is a structured-data error, and a page with no
     * questions on it should carry no claim that it has any.
     *
     * @param  Collection<int, FaqEntry>  $entries
     */
    public function structuredData(Collection $entries): ?string
    {
        if ($entries->isEmpty()) {
            return null;
        }

        $document = [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $entries->map(static fn (FaqEntry $entry): array => [
                '@type' => 'Question',
                'name' => (string) $entry->question,
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $entry->plainAnswer(),
                ],
            ])->all(),
        ];

        // `JSON_HEX_TAG` turns `<` and `>` into `<` and `>`, so a
        // closing `</script>` cannot appear inside the block no matter what an
        // operator typed. The text has no markup in it — the answer field is
        // plain text and always has been — but a JSON-LD block is the one place
        // in the product where operator input sits *inside* a script element,
        // and a defence that only works while a neighbouring rule holds is not
        // a defence.
        $json = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return $json === false ? null : $json;
    }
}
