<?php

declare(strict_types=1);

namespace App\Filament\App\Navigation;

use App\Filament\App\Resources\CancellationPolicyResource;
use App\Filament\App\Resources\CancellationPolicyResource\Pages\ListCancellationPolicies;
use App\Filament\App\Resources\EnquiryResource;
use App\Filament\App\Resources\EnquiryResource\Pages\ListEnquiries;
use App\Filament\App\Resources\QuoteResource;
use App\Filament\App\Resources\QuoteResource\Pages\ListQuotes;
use App\Filament\App\Resources\RatePlanResource;
use App\Filament\App\Resources\RatePlanResource\Pages\ListRatePlans;
use App\Filament\App\Resources\SeasonResource;
use App\Filament\App\Resources\SeasonResource\Pages\ListSeasons;
use App\Filament\App\Resources\VesselBlockResource;
use App\Filament\App\Resources\VesselBlockResource\Pages\ListVesselBlocks;
use App\Filament\App\Resources\VesselResource;
use App\Filament\App\Resources\VesselResource\Pages\ListVessels;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Resource;

/**
 * Screens that share one sidebar entry and are told apart by tabs (Menu 1, 2026-09-16).
 *
 * The product owner's menu has 13 entries where there were 19. Six screens did
 * not go away: each is a second view of a neighbour, so it left the sidebar and
 * became a tab across the top of that neighbour's list — ερωτήματα next to
 * προσφορές, περίοδοι and πολιτικές ακύρωσης next to τιμοκατάλογοι, δεσμεύσεις
 * next to σκάφη.
 *
 * **Tabs between existing screens, not a Filament cluster.** A cluster would
 * move every one of them under a new URL prefix, and the operator manual, the
 * testing guide and people's bookmarks all use the current addresses.
 *
 * The first resource of each set owns the sidebar entry, and that entry stays
 * highlighted on every screen of its set, so the sidebar never shows nothing
 * selected.
 */
final class SiblingScreens
{
    /**
     * Resource → [label key or null for its own navigation label, list page class].
     *
     * @var list<array<class-string<resource>, array{0: string|null, 1: class-string}>>
     */
    private const SETS = [
        [
            QuoteResource::class => ['quotes.quote.nav', ListQuotes::class],
            EnquiryResource::class => [null, ListEnquiries::class],
        ],
        [
            RatePlanResource::class => ['pricing.rate_plan.nav', ListRatePlans::class],
            SeasonResource::class => [null, ListSeasons::class],
            CancellationPolicyResource::class => [null, ListCancellationPolicies::class],
        ],
        [
            VesselResource::class => [null, ListVessels::class],
            VesselBlockResource::class => [null, ListVesselBlocks::class],
        ],
    ];

    /**
     * Every list page that shows the tabs: the render hook's scopes.
     *
     * @return list<class-string>
     */
    public static function listPages(): array
    {
        $pages = [];

        foreach (self::SETS as $set) {
            foreach ($set as [, $page]) {
                $pages[] = $page;
            }
        }

        return $pages;
    }

    /**
     * The tabs for whichever list page is rendering, or none.
     *
     * Only screens this person may open: a tab that 403s is worse than no tab.
     *
     * @param  array<int, string>  $scopes  Filament's render-hook scopes for the page.
     * @return list<array{label: string, url: string, active: bool}>
     */
    public static function tabsFor(array $scopes): array
    {
        foreach (self::SETS as $set) {
            $current = null;

            foreach ($set as $resource => [, $page]) {
                if (in_array($page, $scopes, true)) {
                    $current = $resource;
                }
            }

            if ($current === null) {
                continue;
            }

            $tabs = [];

            foreach ($set as $resource => [$labelKey]) {
                if (! $resource::canViewAny()) {
                    continue;
                }

                $tabs[] = [
                    'label' => $labelKey === null ? $resource::getNavigationLabel() : __($labelKey),
                    'url' => $resource::getUrl('index'),
                    'active' => $resource === $current,
                ];
            }

            return count($tabs) > 1 ? $tabs : [];
        }

        return [];
    }

    /**
     * Keep the owning sidebar entry highlighted on all of its set's screens.
     *
     * @param  class-string<resource>  $owner
     * @param  array<NavigationItem>  $items
     * @return array<NavigationItem>
     */
    public static function highlight(string $owner, array $items): array
    {
        $set = null;

        foreach (self::SETS as $candidate) {
            if (array_key_first($candidate) === $owner) {
                $set = array_keys($candidate);
            }
        }

        if ($set === null) {
            return $items;
        }

        foreach ($items as $item) {
            $item->isActiveWhen(static function () use ($set): bool {
                foreach ($set as $resource) {
                    if (request()->routeIs($resource::getRouteBaseName() . '.*')) {
                        return true;
                    }
                }

                return false;
            });
        }

        return $items;
    }
}
