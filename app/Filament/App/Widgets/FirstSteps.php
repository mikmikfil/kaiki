<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets;

use App\Domain\Operations\Support\FirstSteps as Steps;
use App\Filament\App\Pages\HomePage;
use App\Filament\App\Resources\PortResource;
use App\Filament\App\Resources\ProductResource;
use App\Filament\App\Resources\SeasonResource;
use App\Filament\App\Resources\VesselResource;
use App\Filament\App\Support\ReturnsToFirstSteps;
use Filament\Widgets\Widget;

/**
 * The dashboard, for an operator who has not taken a booking yet (spec OPS-1).
 *
 * Six zeros and a link to an empty list is what the figures widget shows on an
 * operator's first afternoon, and it is a product that looks broken on the day
 * somebody decides whether to keep paying for it. This replaces it until there
 * is something to count.
 *
 * One step at a time, in the order the availability engine requires them. A
 * checklist of four open items is a decision about where to start, and the
 * value of this panel is that there is nothing to decide.
 */
class FirstSteps extends Widget
{
    /**
     * `?from=` on the checklist's links, so the page they open can send the
     * operator back here when the step is done (Mike, 25/9;
     * {@see ReturnsToFirstSteps}).
     */
    public const MARKER = 'first-steps';

    protected static string $view = 'filament.app.widgets.first-steps';

    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Steps::applies();
    }

    /**
     * Not `canView()` on every request: the first booking arriving while this
     * is open is good news, not a reason to answer 403. Nothing here polls or
     * posts today, which is the only reason it never showed; the stress sweep
     * found it (2026-09-23, the fault {@see NeedsAttention} had).
     */
    public function hydrateCanAuthorizeAccess(): void {}

    /** @return list<string> */
    public function getOptional(): array
    {
        return Steps::OPTIONAL;
    }

    /**
     * @return array<string, bool>
     */
    public function getSteps(): array
    {
        return Steps::state();
    }

    public function getNextStep(): ?string
    {
        return Steps::next();
    }

    /**
     * Where each step is done.
     *
     * Built here rather than in the view: a resource URL is a route lookup, and
     * a Blade template calling four of them is a template that fails at render
     * time on the one route somebody renamed.
     *
     * @return array<string, string>
     */
    public function getLinks(): array
    {
        // The steps finished on the page they open carry the marker; the two
        // lists do not, because nothing is finished on a list page.
        return [
            Steps::PORT => self::linkFromChecklist(PortResource::getUrl('create')),
            Steps::VESSEL => self::linkFromChecklist(VesselResource::getUrl('create')),
            Steps::SEASON => self::linkFromChecklist(SeasonResource::getUrl('create')),
            Steps::PRODUCT => self::linkFromChecklist(ProductResource::getUrl('create')),
            Steps::PUBLISHED => ProductResource::getUrl('index'),
            // Only listed for an operator who gets a home page; the link is
            // built either way, since a route lookup costs nothing.
            Steps::HOME_PAGE => self::linkFromChecklist(HomePage::getUrl()),
        ];
    }

    /** `$url`, marked as opened from this checklist. */
    public static function linkFromChecklist(string $url): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . 'from=' . self::MARKER;
    }
}
