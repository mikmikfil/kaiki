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
     * (product owner, 2026-09-17; moved with the greeting on 2026-09-21).
     *
     * A UI icon, not a drawing: the sun from five in the morning until nine at
     * night, the moon for the rest.
     *
     * **It rises with «καλησπέρα», by the product owner's decision.** The
     * alternative was to keep the moon on daylight — dark by six in October,
     * hours before anybody says the word — but an icon that disagrees with the
     * sentence beside it reads as a bug rather than as meteorology.
     *
     * It is not simply `$period === 'evening'`, though: between one and five in
     * the morning the greeting falls back to «Γεια σου», and a sun at three
     * in the morning would be the same mistake from the other end.
     */
    public function getHeading(): string|Htmlable
    {
        $period = self::greetingPeriod();
        $hour = self::localHour();
        $night = $hour >= 21 || $hour < 5;

        return new HtmlString(
            '<span class="ka-greeting-row">'
            . '<span class="ka-greeting">'
            . svg($night ? 'heroicon-o-moon' : 'heroicon-o-sun', 'ka-greeting-ic ' . ($night ? 'is-moon' : 'is-sun'), ['aria-hidden' => 'true'])->toHtml()
            . '<span>' . e(self::greeting($period, self::nameToGreet())) . '</span>'
            . '</span>'
            . self::clock()
            . '</span>'
            // About the height of the capitals, or a touch more, and in a
            // colour of its own: amber for the sun, a soft blue-grey for the
            // moon (product owner: «όχι τόσο διακριτικός»).
            // `space-between` alone parks the clock a word away from the
            // greeting rather than at the far edge, because nothing above it
            // claims the row. Filament wraps the heading in an **unclassed**
            // div which is the flex item that shrinks to its text — 467px of an
            // available 896 — so that div is what has to grow, and the heading
            // and the row after it. Reached by `:has()` rather than by a class
            // because it has none, and scoped to this heading so no other page
            // header changes shape.
            . '<style>.fi-header > div:has(.ka-greeting-row){flex:1 1 auto;width:100%;min-width:0}'
            . '.fi-header-heading:has(.ka-greeting-row){width:100%;min-width:0}'
            . '.ka-greeting-row{display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;gap:.5rem 1.5rem;width:100%}'
            . '.ka-greeting{display:inline-flex;align-items:center;gap:.7rem}'
            . '.ka-greeting-ic{width:2.25rem;height:2.25rem;flex:none;stroke-width:1.6}'
            . '.ka-greeting-ic.is-sun{color:#E0A33B}.ka-greeting-ic.is-moon{color:#7F98BA}'
            . '.ka-clock{font-size:.875rem;font-weight:400;line-height:1.3;color:rgb(var(--gray-500));'
            . 'font-variant-numeric:tabular-nums;white-space:nowrap}'
            . '.dark .ka-clock{color:rgb(var(--gray-400))}</style>',
        );
    }

    /**
     * The day and the time, beside the greeting (product owner, 2026-09-21).
     *
     * **It ticks.** The dashboard is the screen left open on a pontoon all
     * morning, and a clock rendered once at page load is a wrong clock within
     * the minute — worse than no clock, because somebody reads it.
     *
     * The server renders the first value so the heading is right before any
     * script runs, and carries the operator's own timezone and locale on the
     * element. The browser's clock is the only one ticking, but never the
     * browser's *zone*: a skipper checking from a phone still on UK time must
     * not be told it is a different hour in the harbour.
     *
     * `tabular-nums` because the minutes change under the reader's eye, and
     * proportional digits make the whole line shuffle sideways every minute.
     */
    private static function clock(): string
    {
        $timezone = Tenancy::current()?->timezone;
        $timezone = is_string($timezone) && $timezone !== '' ? $timezone : (string) config('kaiki.defaults.timezone', 'Europe/Athens');

        $locale = app()->getLocale();
        $now = Carbon::now()->setTimezone($timezone)->locale($locale);

        return '<span class="ka-clock" data-ka-clock data-tz="' . e($timezone) . '" data-locale="' . e($locale) . '">'
            . e($now->translatedFormat('l j F')) . ' · ' . e($now->format('H:i'))
            . '</span>'
            // Guarded so a Livewire re-render of the heading does not start a
            // second interval on every poll — the element is replaced, the
            // listener is not.
            . '<script>(function(){if(window.__kaClock)return;window.__kaClock=1;'
            . 'var tick=function(){document.querySelectorAll("[data-ka-clock]").forEach(function(el){'
            . 'try{var tz=el.dataset.tz,lo=el.dataset.locale||"el";'
            . 'var d=new Intl.DateTimeFormat(lo,{timeZone:tz,weekday:"long",day:"numeric",month:"long"}).format(new Date());'
            . 'var t=new Intl.DateTimeFormat(lo,{timeZone:tz,hour:"2-digit",minute:"2-digit",hour12:false}).format(new Date());'
            . 'el.textContent=d+" · "+t;}catch(e){}});};'
            . 'setInterval(tick,20000);tick();})();</script>';
    }

    /**
     * Which greeting, on the operator's own clock (product owner, 2026-09-17;
     * evening moved from 17:00 to 21:00 on 2026-09-21):
     *
     * - 05:00–11:59 «Καλημέρα» (`morning`)
     * - 12:00–20:59 «Γεια σου» (`hello`)
     * - 21:00–00:59 «Καλησπέρα» (`evening`)
     * - 01:00–04:59 «Γεια σου» again
     *
     * Five in the afternoon was too early for «καλησπέρα» in Greek: at that
     * hour in summer a skipper is still bringing a boat in, and the word is
     * what you say when the day is done. Nine is when it is.
     *
     * The moon in {@see self::getHeading()} moves with this, by the product
     * owner's decision — an icon that disagrees with the sentence beside it
     * reads as a bug — with one exception it explains there.
     */
    public static function greetingPeriod(?Carbon $now = null): string
    {
        $hour = self::localHour($now);

        return match (true) {
            $hour >= 5 && $hour < 12 => 'morning',
            $hour >= 12 && $hour < 21 => 'hello',
            $hour >= 21 || $hour < 1 => 'evening',
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
