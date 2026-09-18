<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Filament\App\Widgets\DayByBoat;
use App\Filament\App\Widgets\FirstSteps;
use App\Filament\App\Widgets\NeedsAttention;
use App\Filament\App\Widgets\SetupProgress;
use App\Filament\App\Widgets\UnsellableProducts;
use App\Filament\App\Widgets\WeatherOutlook;
use App\Support\Tenancy;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * The operator's first screen, called «Αρχική» in the sidebar (Menu 1, 2026-09-16).
 *
 * The slug and route name are the parent's, so `Filament\Pages\Dashboard::getUrl()`
 * — which `Setup` and `OfferSetupOnce` call — still lands here.
 *
 * ## The day, by boat (product owner, 2026-09-17, «version 3»)
 *
 * The widgets are listed rather than discovered, because their order is the
 * design: getting started while there is nothing to show, then
 * {@see DayByBoat} (the next departure, four boxes, the boats), then the
 * decisions the «Προσοχή» box points down to, then the weather.
 *
 * `OperationsOverview` and `TodayAtSea` are no longer on this page — six
 * figures and a second timeline were what "all too much" meant — but both
 * remain widgets, tested, for any screen that wants them back.
 */
class Dashboard extends BaseDashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-home';

    public static function getNavigationLabel(): string
    {
        return __('panel.nav.home');
    }

    /** The browser tab says the greeting in plain text; the heading adds the icon. */
    public function getTitle(): string|Htmlable
    {
        return self::greeting(self::greetingPeriod(), self::nameToGreet());
    }

    /**
     * «Καλημέρα, Μαρία», or the greeting alone when there is no name
     * (product owner, 2026-09-17).
     */
    public static function greeting(string $period, ?string $name): string
    {
        $name = trim((string) $name);
        $key = 'dashboard.home.greeting.' . $period;

        return $name === '' ? __($key) : __($key . '_named', ['name' => $name]);
    }

    /**
     * The person's own «Προσφώνηση», or the first word of their name when they
     * have not set one: «Καλημέρα, Μαρία», never the surname (product owner,
     * 2026-09-17).
     */
    public static function nameToGreet(): ?string
    {
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        $salutation = trim((string) $user->getAttribute('salutation'));

        if ($salutation !== '') {
            return $salutation;
        }

        $name = trim((string) $user->getAttribute('name'));

        return $name === '' ? null : (string) preg_split('/\s+/u', $name)[0];
    }

    /**
     * «Καλημέρα» or «Καλησπέρα», with a sun or moon before it
     * (product owner, 2026-09-17). A UI icon, not a drawing: the sun from five
     * in the morning until five in the afternoon, the moon for the rest.
     */
    public function getHeading(): string|Htmlable
    {
        $period = self::greetingPeriod();
        $hour = self::localHour();
        $night = $hour >= 17 || $hour < 5;

        return new HtmlString(
            '<span class="ka-greeting">'
            . svg($night ? 'heroicon-o-moon' : 'heroicon-o-sun', 'ka-greeting-ic ' . ($night ? 'is-moon' : 'is-sun'), ['aria-hidden' => 'true'])->toHtml()
            . '<span>' . e(self::greeting($period, self::nameToGreet())) . '</span>'
            . '</span>'
            // About the height of the capitals, or a touch more, and in a
            // colour of its own: amber for the sun, a soft blue-grey for the
            // moon (product owner: «όχι τόσο διακριτικός»).
            . '<style>.ka-greeting{display:inline-flex;align-items:center;gap:.7rem}'
            . '.ka-greeting-ic{width:2.25rem;height:2.25rem;flex:none;stroke-width:1.6}'
            . '.ka-greeting-ic.is-sun{color:#E0A33B}.ka-greeting-ic.is-moon{color:#7F98BA}</style>',
        );
    }

    /**
     * Which greeting, on the operator's own clock (product owner, 2026-09-17):
     *
     * - 05:00–11:59 «Καλημέρα» (`morning`)
     * - 12:00–16:59 «Γεια σου» (`hello`)
     * - 17:00–00:59 «Καλησπέρα» (`evening`)
     * - 01:00–04:59 «Γεια σου» again
     */
    public static function greetingPeriod(?Carbon $now = null): string
    {
        $hour = self::localHour($now);

        return match (true) {
            $hour >= 5 && $hour < 12 => 'morning',
            $hour >= 12 && $hour < 17 => 'hello',
            $hour >= 17 || $hour < 1 => 'evening',
            default => 'hello',
        };
    }

    private static function localHour(?Carbon $now = null): int
    {
        $timezone = Tenancy::current()?->timezone;
        $timezone = is_string($timezone) && $timezone !== '' ? $timezone : (string) config('kaiki.defaults.timezone', 'Europe/Athens');

        return (int) ($now ?? Carbon::now())->copy()->setTimezone($timezone)->format('G');
    }

    /** @return array<class-string> */
    public function getWidgets(): array
    {
        return [
            SetupProgress::class,
            FirstSteps::class,
            DayByBoat::class,
            NeedsAttention::class,
            WeatherOutlook::class,
            UnsellableProducts::class,
        ];
    }

    /** One column: every widget on this page is a full-width band. */
    public function getColumns(): int|string|array
    {
        return 1;
    }
}
