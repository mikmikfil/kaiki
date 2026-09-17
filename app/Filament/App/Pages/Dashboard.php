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
        return self::greeting(self::isEvening(), auth()->user()?->name);
    }

    /**
     * «Καλημέρα, Μαρία», with the first word of the person's own name, or
     * plain «Καλημέρα» when they have not given one (product owner, 2026-09-17).
     */
    public static function greeting(bool $evening, ?string $name): string
    {
        $first = trim((string) strtok(trim((string) $name), ' 	'));
        $key = 'dashboard.home.greeting.' . ($evening ? 'evening' : 'morning');

        return $first === '' ? __($key) : __($key . '_named', ['name' => $first]);
    }

    /**
     * «Καλημέρα» or «Καλησπέρα», with a sun or moon before it
     * (product owner, 2026-09-17). A UI icon, not a drawing.
     */
    public function getHeading(): string|Htmlable
    {
        $evening = self::isEvening();

        return new HtmlString(
            '<span class="ka-greeting">'
            . svg($evening ? 'heroicon-o-moon' : 'heroicon-o-sun', 'ka-greeting-ic ' . ($evening ? 'is-moon' : 'is-sun'), ['aria-hidden' => 'true'])->toHtml()
            . '<span>' . e(self::greeting($evening, auth()->user()?->name)) . '</span>'
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
     * From 16:00 on the operator's own clock, which is when the product owner
     * says good morning becomes good evening; and before 04:00, when nobody
     * greets a night shift with «Καλημέρα».
     */
    public static function isEvening(?Carbon $now = null): bool
    {
        $timezone = Tenancy::current()?->timezone;
        $timezone = is_string($timezone) && $timezone !== '' ? $timezone : (string) config('kaiki.defaults.timezone', 'Europe/Athens');

        $hour = (int) ($now ?? Carbon::now())->copy()->setTimezone($timezone)->format('G');

        return $hour >= 16 || $hour < 4;
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
