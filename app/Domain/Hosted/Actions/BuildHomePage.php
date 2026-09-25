<?php

declare(strict_types=1);

namespace App\Domain\Hosted\Actions;

use App\Domain\Hosted\Support\BlockItems;
use App\Domain\Hosted\Support\BlockSettings;
use App\Domain\Hosted\Support\BlockText;
use App\Domain\Hosted\Support\HostedAsset;
use App\Enums\CrewSpecialty;
use App\Enums\HomeBlockType;
use App\Enums\ProductStatus;
use App\Enums\VesselStatus;
use App\Models\Faq;
use App\Models\HomePageBlock;
use App\Models\Port;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vessel;
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
    public function __construct(private readonly BuildFaqList $faqs) {}

    /**
     * The page, ready to render: one entry per block, in order.
     *
     * @return list<array{block: HomePageBlock, products: Collection<int, Product>, meetingPoint: Port|null, faqs: Collection<int, Faq>, anchor: string|null, links: list<array{label: string, url: string}>}>
     */
    public function __invoke(Tenant $tenant, string $pageName = HomePageBlock::PAGE_HOME): array
    {
        $blocks = HomePageBlock::query()->onPage($pageName)->forPage()->get();

        // Only the home page has a default. An about page nobody has written is
        // no page at all ({@see HomeBlockType::aboutLayout()}).
        if ($blocks->isEmpty() && $pageName === HomePageBlock::PAGE_HOME) {
            $blocks = $this->default($tenant);
        }

        $has = static fn (HomeBlockType $type): bool => $blocks->contains(static fn (HomePageBlock $block): bool => $block->type === $type);

        // The about page's mounts (2026-09-24), each loaded once and only when
        // a section on the page asks for it.
        $vessels = $has(HomeBlockType::Fleet) || $has(HomeBlockType::Credentials) ? $this->vessels() : collect();
        $crew = $has(HomeBlockType::Crew) ? $this->crew($tenant) : collect();

        $catalogue = $blocks->contains(fn (HomePageBlock $block): bool => $block->type === HomeBlockType::Trips)
            ? $this->catalogue()
            : collect();

        // The tenant-wide entries, loaded once however many FAQ blocks a page
        // has — and not at all when it has none, which is every page that has
        // not been edited since #103.
        $faqs = $blocks->contains(fn (HomePageBlock $block): bool => $block->type === HomeBlockType::Faq)
            ? ($this->faqs)()
            : collect();

        // The trips the hero's and the call-to-action bands' buttons name, in one query for the
        // whole page, and only the ones still on sale: a button to a trip the
        // operator has since withdrawn would lead to a 404.
        $linkedIds = $blocks
            ->filter(fn (HomePageBlock $block): bool => $block->type->maxButtons() > 0)
            ->flatMap(fn (HomePageBlock $block): array => array_column($block->buttonEntries(), 'product_id'))
            ->filter()
            ->unique()
            ->values();

        $linked = $linkedIds->isEmpty()
            ? collect()
            : Product::query()->whereIn('id', $linkedIds->all())->where('status', ProductStatus::Active)->get()->keyBy('id');

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
                // The same collection for every FAQ block on the page. Two of
                // them is an operator repeating themselves rather than a second
                // question set, and the alternative — a query per block — is the
                // thing the catalogue above is loaded once to avoid.
                'faqs' => $block->type === HomeBlockType::Faq ? $faqs : collect(),
                'anchor' => $anchor ?? match ($block->type) {
                    HomeBlockType::Contact => 'contact',
                    HomeBlockType::Faq => 'faq',
                    default => null,
                },
                'links' => $block->type->maxButtons() > 0 ? $this->linksFor($block, $tenant, $linked) : [],
                'vessels' => $block->type === HomeBlockType::Fleet ? $this->fleetFor($block, $vessels) : collect(),
                'crew' => $block->type === HomeBlockType::Crew ? $crew : collect(),
                'credentials' => $block->type === HomeBlockType::Credentials ? $this->credentials($tenant, $vessels) : [],
                'port' => $block->type === HomeBlockType::MeetingPoint ? $this->portFor($block) : null,
                'page' => $pageName,
            ];
        }

        return $page;
    }

    /**
     * A hero's or a call-to-action band's buttons, each resolved to a label and a URL.
     *
     * Every URL is built here from a route this application names — the
     * operator chose a *target*, never typed an address — so a button cannot
     * leave the operator's own site. {@see BlockItems}
     *
     * @param  Collection<int, Product>  $linked
     * @return list<array{label: string, url: string}>
     */
    public function linksFor(HomePageBlock $block, Tenant $tenant, Collection $linked): array
    {
        $locale = app()->getLocale();
        $operator = ['operator' => $tenant->slug, 'lang' => $locale];
        $links = [];

        foreach ($block->buttonEntries() as $entry) {
            $url = match ($entry['target']) {
                'trips' => route('hosted.index', $operator) . '#trips',
                'search' => route('hosted.search', $operator),
                'contact' => route('hosted.contact', $operator),
                'about' => route('hosted.about', $operator),
                'trip' => ($product = $linked->get($entry['product_id'])) instanceof Product
                    ? route('hosted.product', [...$operator, 'product' => $product->slug])
                    : null,
                'page' => rtrim(route('hosted.index', ['operator' => $tenant->slug]), '/') . '/' . $entry['path'],
                default => null,
            };

            $label = BlockItems::text($entry, 'label', $locale);

            if ($url !== null && $label !== '') {
                $links[] = ['label' => $label, 'url' => $url];
            }
        }

        return $links;
    }

    /**
     * The page's `meta description`, from the first prose it can find.
     *
     * An operator's own first paragraph describes their business better than
     * any template sentence, and it is already written. The generic line is the
     * fallback rather than the default, so a page that has been edited stops
     * advertising itself in platform copy.
     */
    public function metaDescription(Tenant $tenant, string $pageName = HomePageBlock::PAGE_HOME): string
    {
        foreach ($this->__invoke($tenant, $pageName) as $entry) {
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
     * The active boats, in «Σκάφη»'s order, each with how many trips on sale
     * use it — the card's «4 εκδρομές» link — and its first photograph.
     *
     * @return Collection<int, Vessel>
     */
    public function vessels(): Collection
    {
        // The trips on sale that use each boat, in the catalogue's order. The
        // card links each one straight to its page (2026-09-24): a search link
        // filtered by boat found nothing when the operator's vessel filter was
        // off, and it passed the boat's id where the search reads a uuid.
        $trips = Product::query()
            ->where('status', ProductStatus::Active)
            ->whereNotNull('vessel_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'uuid', 'slug', 'title', 'vessel_id', 'sort_order'])
            ->groupBy('vessel_id');

        return Vessel::query()
            ->where('status', VesselStatus::Active)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->each(static function (Vessel $vessel) use ($trips): void {
                $vessel->setAttribute('trips_list', $trips->get($vessel->getKey(), collect())->values());
                $vessel->setAttribute('trips_on_sale', $vessel->getAttribute('trips_list')->count());
                // Relative, like every other picture on these pages: the panel's host is
                // not in the hosted page's policy.
                $vessel->setAttribute('photo_url', HostedAsset::url(is_string($vessel->images[0]['path'] ?? null) ? $vessel->images[0]['path'] : null));
            });
    }

    /**
     * The boats one fleet section shows: the ticked ones, in «Σκάφη»'s order,
     * or all of them when none is ticked.
     *
     * @param  Collection<int, Vessel>  $vessels
     * @return Collection<int, Vessel>
     */
    public function fleetFor(HomePageBlock $block, Collection $vessels): Collection
    {
        $ids = (array) $block->setting('vessel_ids', []);

        return $ids === []
            ? $vessels->values()
            : $vessels->filter(static fn (Vessel $vessel): bool => in_array($vessel->getKey(), $ids, true))->values();
    }

    /**
     * Captains first, then deckhands, from «Ομάδα». Nobody whose specialty is
     * «Άλλο» or unset: the section is about who is on the boat.
     *
     * `User` is not tenant-scoped, so the filter is written here.
     *
     * @return Collection<int, User>
     */
    public function crew(Tenant $tenant): Collection
    {
        return User::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('is_super_admin', false)
            ->whereIn('specialty', [CrewSpecialty::Captain->value, CrewSpecialty::Deckhand->value])
            ->orderBy('name')
            ->get()
            ->sortBy(static fn (User $user): int => $user->specialty === CrewSpecialty::Captain ? 0 : 1)
            ->each(static fn (User $user) => $user->setAttribute('photo_url', HostedAsset::url($user->photo_path)))
            ->values();
    }

    /**
     * What the licences section lists, from the tenant's own record and its
     * boats. A line whose number is missing is left out rather than printed
     * empty.
     *
     * @param  Collection<int, Vessel>  $vessels
     * @return array{licences: array<string, int>, legal_name: string, vat_number: string|null, tax_office: string|null, gemi_number: string|null}
     */
    public function credentials(Tenant $tenant, Collection $vessels): array
    {
        $licences = [];

        foreach ($vessels as $vessel) {
            if ($vessel->licence_type !== null) {
                $licences[$vessel->licence_type->value] = ($licences[$vessel->licence_type->value] ?? 0) + 1;
            }
        }

        return [
            'licences' => $licences,
            'legal_name' => filled($tenant->legal_name) ? (string) $tenant->legal_name : $tenant->name,
            'vat_number' => filled($tenant->vat_number) ? (string) $tenant->vat_number : null,
            'tax_office' => filled($tenant->taxOfficeName()) ? (string) $tenant->taxOfficeName() : null,
            'gemi_number' => filled($tenant->gemi_number) ? (string) $tenant->gemi_number : null,
        ];
    }

    /** The chosen port, or the first active one in «Λιμάνια». */
    public function portFor(HomePageBlock $block): ?Port
    {
        $id = $block->setting('meeting_point_id');

        $port = is_numeric($id) ? Port::query()->where('is_active', true)->find((int) $id) : null;
        $port ??= Port::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->first();

        return $port;
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
                HomeBlockType::Faq => $this->inBothLocales('hosted.blocks.faq.heading'),
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
