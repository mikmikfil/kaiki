<?php

declare(strict_types=1);

namespace App\Http\Controllers\Hosted;

use App\Domain\Branding\Actions\GetBrandPayload;
use App\Domain\Catalog\Queries\PublicProductQuery;
use App\Domain\Hosted\Actions\BuildProductPage;
use App\Domain\Hosted\Support\BlockText;
use App\Domain\Hosted\Support\HostedUrl;
use App\Domain\Hosted\Support\ProductJsonLd;
use App\Models\Product;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `book.{platform-domain}/{operator-slug}/{product-slug}` — one trip (HOS-1, HOS-2).
 *
 * The page a search engine lands on and the page an operator sends a link to.
 * Everything on it is server-rendered (HOS-4): with scripts blocked a visitor
 * still gets the description, the meeting point, the itinerary, the policy and
 * the price, and loses only the date picker.
 *
 * ## A missing trip is a 404, never a redirect
 *
 * An inactive, archived, draft or deleted product returns 404 rather than
 * bouncing to the operator's home page. A redirect tells a crawler *"this page
 * moved"* — so the trip's search ranking follows the redirect to the landing
 * page and the operator's own home page starts ranking for a trip that is no
 * longer sold. 404 says what actually happened: it is gone.
 *
 * {@see PublicProductQuery::find()} is what decides this. `sellable()` removes
 * anything a guest may not see *inside the query*, so there is no status branch
 * here that could be forgotten, and a product belonging to another operator is
 * indistinguishable from one that never existed (SEC-1, SEC-2).
 *
 * ## The canonical is the slug URL, whatever the request used
 *
 * `find()` accepts a uuid as well as a slug, because the widget embed carries a
 * uuid and the two are the same resource. That would be two URLs for one page,
 * which is the duplicate-content problem HOS-5's canonical exists to answer —
 * so the canonical and the alternates are always built from the slug.
 */
class ProductPageController extends HostedController
{
    /**
     * The product this request is about, resolved once.
     *
     * {@see self::alternates()} runs inside {@see HostedController::render()}
     * and needs the same row the action already loaded with all its relations.
     * Looking it up again there would be a second detail query — five joins
     * deep — on the hottest guest-facing read in the product.
     */
    private ?Product $product = null;

    public function __construct(
        GetBrandPayload $brand,
        private readonly BuildProductPage $page,
    ) {
        parent::__construct($brand);
    }

    public function show(Request $request, string $operator, string $product): Response
    {
        $tenant = $this->tenant();
        $locale = $this->resolveLocale($request, $tenant);

        $model = $this->product = PublicProductQuery::find($product);

        abort_unless($model instanceof Product, Response::HTTP_NOT_FOUND);

        $page = ($this->page)($model, $locale);

        return $this->render($request, $tenant, 'hosted.product', fn (): array => [
            ...$page,
            // HOS-2's own field: the operator's `meta_description` when they
            // wrote one, their summary when they did not, and the trip's
            // description as the last resort. A generic platform sentence is
            // not among the options — every product has at least a title.
            'metaDescription' => $this->metaDescription($model),
            'metaTitle' => $model->meta_title ?? $model->title,
            'canonical' => HostedUrl::product($tenant, $model, $locale),
            'schema' => ProductJsonLd::json(
                $tenant,
                $model,
                $page['departures'],
                $locale,
                $page['fromPriceCents'],
            ),
        ], $locale);
    }

    /**
     * The `hreflang` alternates, on the slug rather than on whatever was typed.
     *
     * Overrides {@see HostedController::alternates()}, which builds them from
     * the current URL — correct for every other hosted page and wrong for this
     * one, because a uuid URL would then advertise itself as the alternate of
     * the canonical it is a duplicate of.
     *
     * @return array<string, string>
     */
    protected function alternates(Request $request): array
    {
        if (! $this->product instanceof Product) {
            return parent::alternates($request);
        }

        $tenant = $this->tenant();

        return [
            'el' => HostedUrl::product($tenant, $this->product, 'el'),
            'en' => HostedUrl::product($tenant, $this->product, 'en'),
        ];
    }

    /** At most 160 characters of the best description the operator has written. */
    protected function metaDescription(Product $product): string
    {
        foreach ([$product->meta_description, $product->summary, $product->description] as $candidate) {
            $line = BlockText::line($candidate);

            if ($line !== '') {
                return $line;
            }
        }

        return (string) $product->title;
    }
}
