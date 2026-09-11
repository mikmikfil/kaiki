<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Filament\App\Resources\ApiKeyResource;
use App\Filament\App\Resources\AuditLogResource;
use App\Filament\App\Resources\ExportResource;
use App\Filament\App\Resources\FaqResource;
use App\Filament\App\Resources\NotificationLogResource;
use App\Filament\App\Resources\StaffResource;
use App\Filament\App\Resources\WebhookEndpointResource;
use App\Support\Tenancy;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Illuminate\Contracts\Support\Htmlable;

/**
 * «Ρυθμίσεις», as a page of cards rather than a menu (product owner, 2026-09-11).
 *
 * ## What it replaced
 *
 * #127 moved fifteen screens into a collapsed «Ρυθμίσεις» group, which kept
 * them out of the way and made them hard to find: a closed list says nothing
 * about what is inside it, and an open one is fifteen lines of labels with no
 * explanation. Each card here carries its screen's own icon and title, plus one
 * line saying what the screen is for, and the whole card is the link.
 *
 * ## Every card asks its own screen
 *
 * A card appears only when the screen it leads to would open for this user:
 * `canAccess()` on a page, `canAccess()` (which is `viewAny`) on a resource.
 * The hub keeps no role matrix of its own, so it cannot drift from TEN-8. If a
 * manager may not open «Συνδέσεις», the manager gets no card for it, and that
 * is decided in exactly one place. A user with no card at all has no hub either.
 *
 * ## Not a Filament cluster
 *
 * A cluster is Filament's own version of this, and it would move every screen
 * under `/app/settings/…`. The operator manual, the testing walkthrough and the
 * setup guide all link to the existing addresses, and a bookmark to
 * `/app/integrations` should keep working. So the screens keep their routes and
 * only leave the sidebar ({@see $shouldRegisterNavigation} on each), and
 * `PanelRenderHooks` puts a way back to this page at the top of each one.
 */
class Settings extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    /**
     * Not in the menu itself: pinned to the very bottom of the sidebar instead.
     *
     * The product owner's placement (2026-09-11), and the usual one — settings
     * are what you go looking for, not what you do every morning. Filament
     * always places ungrouped items above every group, so a registered item
     * could only ever sit at the top. `PanelRenderHooks` draws this one in the
     * sidebar's footer, with Filament's own item component, so it looks and
     * behaves like every other entry (badge, active state, collapsed tooltip).
     */
    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.app.pages.settings';

    /**
     * The cards, section by section, in the order they are read.
     *
     * Keys name the card's description in `settings_hub.cards.*`. The title is
     * the screen's own navigation label, so a card and the page it opens can
     * never disagree about what the page is called.
     *
     * @var array<string, array<string, class-string<Page|resource>>>
     */
    public const SECTIONS = [
        'business' => [
            'branding' => Branding::class,
            'staff' => StaffResource::class,
            'payments' => PaymentSettings::class,
            'integrations' => Integrations::class,
        ],
        'website' => [
            'home_page' => HomePage::class,
            'faq' => FaqResource::class,
            'search' => SearchSettings::class,
            'domains' => Domains::class,
        ],
        'records' => [
            'failures' => Failures::class,
            'notifications' => NotificationLogResource::class,
            'exports' => ExportResource::class,
            'audit' => AuditLogResource::class,
        ],
        'advanced' => [
            'calendar_sync' => CalendarSync::class,
            'api_keys' => ApiKeyResource::class,
            'webhooks' => WebhookEndpointResource::class,
        ],
    ];

    public static function getNavigationLabel(): string
    {
        return __('settings_hub.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('settings_hub.title');
    }

    public function getSubheading(): ?string
    {
        return __('settings_hub.subheading');
    }

    /**
     * Open to anybody with at least one card, and to nobody else.
     *
     * The tenant check first, because Filament asks this while building the
     * menu on the login page, where there is none and a tenant-owned query
     * throws (TEN-4, `NavigationWithoutTenantTest`).
     */
    public static function canAccess(): bool
    {
        if (! Tenancy::check()) {
            return false;
        }

        foreach (self::destinations() as $class) {
            if ($class::canAccess()) {
                return true;
            }
        }

        return false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /**
     * The failure count, lifted onto the one sidebar item that is left.
     *
     * «Τι πήγε στραβά» had its own red badge in the sidebar, so an operator
     * noticed on the morning something failed without opening anything. Moving
     * that screen behind this page must not take that signal with it, so its
     * badge comes along. The card carries it as well.
     */
    public static function getNavigationBadge(): ?string
    {
        return Failures::canAccess() ? Failures::getNavigationBadge() : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /**
     * Whether the sidebar item should be lit: on this page, or inside any card.
     *
     * Without this, opening «Επωνυμία» from here would leave nothing in the
     * sidebar highlighted, and the operator could not tell where they are.
     */
    public static function isCurrent(): bool
    {
        return request()->routeIs(self::activeRoutePatterns());
    }

    /**
     * Every screen the hub leads to.
     *
     * @return list<class-string<Page|resource>>
     */
    public static function destinations(): array
    {
        $classes = [];

        foreach (self::SECTIONS as $cards) {
            foreach ($cards as $class) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * The sections that have at least one card this user may open.
     *
     * @return list<array{key: string, heading: string, cards: list<array{key: string, title: string, description: string, icon: string|Htmlable|null, url: string, badge: string|null, badge_color: string|array<string>|null}>}>
     */
    public function sections(): array
    {
        $sections = [];

        foreach (self::SECTIONS as $section => $destinations) {
            $cards = [];

            foreach ($destinations as $key => $class) {
                if (! $class::canAccess()) {
                    continue;
                }

                $cards[] = [
                    'key' => $key,
                    'title' => $class::getNavigationLabel(),
                    'description' => __("settings_hub.cards.{$key}"),
                    'icon' => $class::getNavigationIcon(),
                    'url' => self::urlOf($class),
                    'badge' => $class::getNavigationBadge(),
                    'badge_color' => $class::getNavigationBadgeColor(),
                ];
            }

            if ($cards !== []) {
                $sections[] = [
                    'key' => $section,
                    'heading' => __("settings_hub.sections.{$section}"),
                    'cards' => $cards,
                ];
            }
        }

        return $sections;
    }

    /** @param  class-string<Page|resource>  $class */
    public static function urlOf(string $class): string
    {
        return is_subclass_of($class, Resource::class)
            ? $class::getUrl('index')
            : $class::getUrl();
    }

    /** @return list<string> */
    private static function activeRoutePatterns(): array
    {
        $patterns = [static::getRouteName()];

        foreach (self::destinations() as $class) {
            // A resource is several routes (list, create, edit), so match all
            // of them. A page is exactly one.
            if (is_subclass_of($class, Resource::class)) {
                $patterns[] = $class::getRouteBaseName() . '.*';
            } elseif (is_subclass_of($class, Page::class)) {
                $patterns[] = $class::getRouteName();
            }
        }

        return $patterns;
    }
}
