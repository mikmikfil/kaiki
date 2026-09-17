<?php

declare(strict_types=1);

namespace App\Filament\App\Navigation;

use App\Filament\App\Pages\Analytics;
use App\Filament\App\Pages\Settings;
use App\Support\Tenancy;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;

/**
 * Menu 1 as boxes, for a phone (product owner, 2026-09-17, mobile direction A).
 *
 * On a phone the panel has no sidebar. A «Μενού» button in the top bar opens
 * the whole menu over the page as a grid of big boxes, grouped exactly as the
 * sidebar groups them, so nothing is tucked inside a long list and a thumb can
 * reach every screen.
 *
 * **Read from Filament's own navigation**, not a second list: whatever the
 * sidebar shows this person — permissions, badges, the entry kept highlighted
 * for its sibling screens — the boxes show too, and a screen added to the
 * sidebar appears here without anybody remembering this file. The foot of the
 * sidebar («Στατιστικά», «Ρυθμίσεις») lives in a render hook rather than in
 * the navigation, so those two are added here under the same checks.
 */
final class BoxMenu
{
    /**
     * @return list<array{label: string|null, items: list<array{label: string, url: string, icon: string|null, badge: string|null, active: bool}>}>
     */
    public static function groups(): array
    {
        if (! Tenancy::check()) {
            return [];
        }

        $groups = [];

        foreach (Filament::getNavigation() as $group) {
            if (! $group instanceof NavigationGroup) {
                continue;
            }

            $items = [];

            foreach ($group->getItems() as $item) {
                if (! $item instanceof NavigationItem || ! $item->isVisible()) {
                    continue;
                }

                $items[] = [
                    'label' => (string) $item->getLabel(),
                    'url' => (string) $item->getUrl(),
                    'icon' => self::icon($item->getIcon()),
                    'badge' => self::badge($item->getBadge()),
                    'active' => $item->isActive(),
                ];
            }

            if ($items !== []) {
                $groups[] = ['label' => $group->getLabel(), 'items' => $items];
            }
        }

        $foot = [];

        if (Analytics::canAccess()) {
            $foot[] = [
                'label' => Analytics::getNavigationLabel(),
                'url' => Analytics::getUrl(),
                'icon' => self::icon(Analytics::getNavigationIcon()),
                'badge' => null,
                'active' => request()->routeIs(Analytics::getRouteName()),
            ];
        }

        if (Settings::canAccess()) {
            $foot[] = [
                'label' => Settings::getNavigationLabel(),
                'url' => Settings::getUrl(),
                'icon' => self::icon(Settings::getNavigationIcon()),
                'badge' => self::badge(Settings::getNavigationBadge()),
                'active' => Settings::isCurrent(),
            ];
        }

        if ($foot !== []) {
            $groups[] = ['label' => null, 'items' => $foot];
        }

        return $groups;
    }

    private static function icon(mixed $icon): ?string
    {
        return is_string($icon) && $icon !== '' ? $icon : null;
    }

    private static function badge(mixed $badge): ?string
    {
        return is_scalar($badge) && (string) $badge !== '' ? (string) $badge : null;
    }
}
