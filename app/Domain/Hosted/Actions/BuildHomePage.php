<?php

declare(strict_types=1);

namespace App\Domain\Hosted\Actions;

use App\Domain\Hosted\Support\BlockSettings;
use App\Domain\Hosted\Support\BlockText;
use App\Enums\HomeBlockType;
use App\Enums\ProductStatus;
use App\Models\HomePageBlock;
use App\Models\Port;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Support\Collection;

/**
 * The operator's home page: their blocks, or the default if they have none.
 *
 * ## The default is a page, not an empty state
 *
 * An operator who has never opened the editor has no rows, and the honest
 * options are a blank page or a synthesised one. #101 already served the second
 * — the operator's name and their trips — and taking that away the day the
 * editor shipped would be a regression dressed as a feature. So the default is
 * the same page, expressed in blocks, which also means "start from the default"
 * in the editor hands the operator what their guests were already seeing rather
 * than a canvas.
 *
 * The synthesised blocks are **not saved**. Saving them on first render would
 * make a read request write, on the page with the highest traffic in the
 * product, in a tenant that may be read-only (HOS-10) — and it would fire on a
 * crawler.
 *
 * ## Every block arrives at the view fully resolved
 *
 * The partials receive their products, their meeting point and their anchor
 * rather than looking any of it up, because a template that queries is a
 * template that does it once per block. Two trips blocks — "our sunset cruises"
 * and "everything else" — are a page an operator will build, and the naive
 * version issues a query per block plus a vessel and meeting-point query per
 * product. The catalogue is small enough (`docs/data-model.md` sizes a
 * five-product tenant at a handful of rows) that loading active products once
 * and slicing in memory is both faster and simpler.
 */
class BuildHomePage
{
    /**
     * The page, ready to render: one entry per block, in order.
     *
     * @return list<array{block: HomePageBlock, products: Collection<int, Product>, meetingPoint: Port|null, anchor: string|null}>
     */
    public function __invoke(Tenant $tenant): array
    {
        $blocks = HomePageBlock::query()->forPage()->get();

        if ($blocks->isEmpty()) {
            $blocks = $this->default($tenant);
        }

        $catalogue = $blocks->contains(fn (HomePageBlock $block): bool => $block->type === HomeBlockType::Trips)
            ? $this->catalogue()
            : collect();

        $page = [];
        $anchored = false;

        foreach ($blocks as $block) {
            $isTrips = $block->type === HomeBlockType::Trips;

            // The hero's call to action points at `#trips`, so exactly one block
            // may carry that id — the first, which is what an operator means by
            // "book now". Deciding it here rather than in the template is what
            // stops a second trips block silently stealing the anchor.
            $anchor = $isTrips && ! $anchored ? 'trips' : null;
            $anchored = $anchored || $anchor !== null;

            $page[] = [
                'block' => $block,
                'products' => $isTrips ? $this->productsFor($block, $catalogue) : collect(),
                'meetingPoint' => $block->type === HomeBlockType::Contact ? $this->meetingPointFor($block) : null,
                'anchor' => $anchor ?? ($block->type === HomeBlockType::Contact ? 'contact' : null),
            ];
        }

        return $page;
    }

    /**
     * The page's `meta description`, from the first prose it can find.
     *
     * An operator's own first paragraph describes their business better than
     * any template sentence, and it is already written. The generic line is the
     * fallback rather than the default, so a page that has been edited stops
     * advertising itself in platform copy.
     */
    public function metaDescription(Tenant $tenant): string
    {
        foreach ($this->__invoke($tenant) as $entry) {
            $body = $entry['block']->body;

            if ($entry['block']->type->hasProse() && is_string($body) && trim($body) !== '') {
                return BlockText::line($body);
            }
        }

        return __('hosted.index.meta_description', ['operator' => $tenant->name]);
    }

    /**
     * The products a trips block should show.
     *
     * @param  Collection<int, Product>  $catalogue
     * @return Collection<int, Product>
     */
    public function productsFor(HomePageBlock $block, Collection $catalogue): Collection
    {
        $settings = $block->settings();

        $products = match ($settings['source']) {
            BlockSettings::SOURCE_FEATURED => $catalogue->filter(
                static fn (Product $product): bool => (bool) $product->is_featured,
            ),
            BlockSettings::SOURCE_CATEGORY => $catalogue->filter(
                static fn (Product $product): bool => $product->category->value === $settings['category'],
            ),
            default => $catalogue,
        };

        $limit = (int) $settings['limit'];

        return $limit > 0 ? $products->take($limit)->values() : $products->values();
    }

    /**
     * Every active product, loaded once for the whole page.
     *
     * @return Collection<int, Product>
     */
    public function catalogue(): Collection
    {
        return Product::query()
            ->where('status', ProductStatus::Active)
            ->with(['vessel', 'meetingPoint'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /** The meeting point a contact block names, if it names one. */
    public function meetingPointFor(HomePageBlock $block): ?Port
    {
        $id = $block->setting('meeting_point_id');

        if (! is_numeric($id)) {
            return null;
        }

        return Port::query()->find((int) $id);
    }

    /**
     * One lang key, resolved in both locales.
     *
     * A translatable column needs both, and asking for the current request's
     * locale only would give an operator a default page that is Greek on the
     * Greek URL and *also* Greek on the English one — the fallback chain has
     * nothing else to reach for.
     *
     * @return array<string, string>
     */
    protected function inBothLocales(string $key): array
    {
        return ['el' => (string) __($key, [], 'el'), 'en' => (string) __($key, [], 'en')];
    }

    /**
     * The page an operator gets before they have written one.
     *
     * Unsaved models, deliberately — see the class docblock. `id` is left unset
     * rather than faked, so anything that tried to treat one as a row (a policy
     * check, a route-model binding, an update) fails loudly instead of writing
     * to id 0.
     *
     * @return Collection<int, HomePageBlock>
     */
    public function default(Tenant $tenant): Collection
    {
        $blocks = collect();
        $order = 0;

        foreach (HomeBlockType::defaultLayout() as $type) {
            $block = new HomePageBlock([
                'type' => $type,
                'sort_order' => $order++,
                'is_visible' => true,
                'settings' => BlockSettings::defaults($type),
            ]);

            // The hero carries the tenant's own name rather than platform copy.
            // A default page reading "Welcome to our boat tours" in two
            // languages would be worse than the operator's name alone, because
            // an operator who never edits it ships somebody else's sentence.
            //
            // The other two carry the section headings #101's page already had,
            // which is what makes this the same page rather than a similar one.
            $heading = match ($type) {
                HomeBlockType::Hero => ['el' => $tenant->name, 'en' => $tenant->name],
                HomeBlockType::Trips => $this->inBothLocales('hosted.index.trips'),
                HomeBlockType::Contact => $this->inBothLocales('hosted.blocks.contact.heading'),
                default => null,
            };

            if ($heading !== null) {
                $block->setTranslations('heading', $heading);
            }

            $blocks->push($block);
        }

        return $blocks;
    }
}
