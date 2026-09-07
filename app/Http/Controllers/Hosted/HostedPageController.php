<?php

declare(strict_types=1);

namespace App\Http\Controllers\Hosted;

use App\Domain\Branding\Actions\GetBrandPayload;
use App\Domain\Catalog\Support\SearchFormOptions;
use App\Domain\Hosted\Actions\BuildHomePage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `book.{platform-domain}/{operator-slug}` — the operator's own page (HOS-1).
 *
 * ## Everything renders without JavaScript
 *
 * HOS-4: *"Hosted pages are server-rendered Blade, work without JavaScript for
 * all content, and mount the widget only for the booking interaction."* So
 * this is Blade with no build step and no hydration, and the only thing that
 * can be missing when scripts are blocked is the date picker.
 *
 * That is not a nicety. A crawler runs no JavaScript, and HOS-2's whole point
 * is that these pages are what search engines see; a visitor in a harbour on
 * one bar of signal is the other half.
 *
 * ## Everything else is {@see HostedController}'s
 *
 * The tenant resolution, the locale, the `hreflang` alternates and the shared
 * view data moved there in #104, when the product page became the second
 * controller that needed all four. Their reasoning moved with them.
 */
class HostedPageController extends HostedController
{
    public function __construct(
        GetBrandPayload $brand,
        private readonly BuildHomePage $homePage,
    ) {
        parent::__construct($brand);
    }

    /**
     * The operator's landing page, composed from the blocks of #102.
     *
     * An operator who has never opened the editor is not a special case here:
     * {@see BuildHomePage} synthesises the default layout, which is the page
     * #101 already served. So this method has one path and the template has no
     * empty state.
     */
    public function index(Request $request): Response
    {
        $tenant = $this->tenant();
        $locale = $this->resolveLocale($request, $tenant);

        return $this->render($request, $tenant, 'hosted.index', fn (): array => [
            'blocks' => ($this->homePage)($tenant),
            // The hero carries the same search form the search page does, with
            // the same fields the operator enabled. Resolved here for the same
            // reason it is there: a template that queries queries on every
            // render.
            ...SearchFormOptions::for($tenant),
            // The operator's own first paragraph describes their business
            // better than a template sentence, and it is already written.
            'metaDescription' => $this->homePage->metaDescription($tenant),
        ], $locale);
    }

    /** The operator's legal and policy page (HOS-9). */
    public function legal(Request $request): Response
    {
        $tenant = $this->tenant();
        $locale = $this->resolveLocale($request, $tenant);

        return $this->render($request, $tenant, 'hosted.legal', static fn (): array => [], $locale);
    }
}
