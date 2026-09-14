<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Domain\Analytics\Support\AnalyticsFigures;
use App\Domain\Analytics\Support\LocalRange;
use App\Filament\App\Widgets\OperationsOverview;
use App\Support\Authorization\Capability;
use App\Support\Format\Locales;
use App\Support\Format\MoneyFormatter;
use App\Support\Tenancy;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use NumberFormatter;

/**
 * The operator's own numbers, over a period they choose.
 *
 * ## A page, and not six widgets on the dashboard
 *
 * The dashboard answers *what is happening today* — six figures for this
 * morning, each a link to the list behind it. This answers *how did we do*,
 * which is a different question asked at a different time, usually sitting down
 * with a coffee and last month in mind. Putting it on the dashboard would push
 * today's departures below the fold to make room for August.
 *
 * ## The period lives in the URL
 *
 * `#[Url]`, so a range can be sent to an accountant, bookmarked, or opened
 * twice side by side. A report whose state exists only inside a Livewire
 * component is a report nobody can share, and the first thing an operator does
 * with a good number is send it to somebody.
 *
 * ## Revenue is behind `ViewFinancials`, the rest is not
 *
 * The same split {@see OperationsOverview} makes on
 * the dashboard. A manager who runs the quay needs occupancy and cancellations
 * to do their job; what the business took is the owner's business. Crew reach
 * none of it — TEN-8 gives them a passenger list and nothing else.
 *
 * All arithmetic is in {@see AnalyticsFigures}, which is where the definitions
 * and their tests live. Nothing on this page computes a number of its own.
 */
class Analytics extends Page
{
    public const PRESET_LAST_30 = 'last_30';

    public const PRESET_THIS_MONTH = 'this_month';

    public const PRESET_LAST_MONTH = 'last_month';

    public const PRESET_THIS_YEAR = 'this_year';

    public const PRESET_CUSTOM = 'custom';

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.app.pages.analytics';

    /** Which of the named periods, or the operator's own dates. */
    #[Url(as: 'period', keep: false)]
    public string $preset = self::PRESET_LAST_30;

    /** Only read when `preset` is `custom`, and always a local date. */
    #[Url(as: 'from', keep: false)]
    public string $from = '';

    #[Url(as: 'to', keep: false)]
    public string $to = '';

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('analytics.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('analytics.title');
    }

    public function getSubheading(): ?string
    {
        return __('analytics.subtitle');
    }

    /**
     * Anybody who may see a booking may see how the bookings went.
     *
     * The money blocks are gated separately by {@see self::showsMoney()} — a
     * manager who cannot see financials still gets occupancy, channels and
     * cancellations, which are the numbers their own job turns on.
     */
    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user !== null
            && ($user->hasCapability(Capability::ManageBookings) || $user->hasCapability(Capability::ViewFinancials));
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        if (! in_array($this->preset, self::presets(), true)) {
            $this->preset = self::PRESET_LAST_30;
        }
    }

    public function showsMoney(): bool
    {
        return Auth::user()?->hasCapability(Capability::ViewFinancials) ?? false;
    }

    /** @return list<string> */
    public static function presets(): array
    {
        return [
            self::PRESET_LAST_30,
            self::PRESET_THIS_MONTH,
            self::PRESET_LAST_MONTH,
            self::PRESET_THIS_YEAR,
            self::PRESET_CUSTOM,
        ];
    }

    /** @return array<string, string> */
    public function presetOptions(): array
    {
        $options = [];

        foreach (self::presets() as $preset) {
            $options[$preset] = (string) __("analytics.range.presets.{$preset}");
        }

        return $options;
    }

    public function timezone(): string
    {
        return Tenancy::current()?->timezone ?: (string) config('app.timezone', 'UTC');
    }

    /**
     * The period being shown.
     *
     * A named preset is resolved every time rather than written into the two
     * date fields: "this month" on the first of September must mean September,
     * and a stored pair of dates would still say August.
     */
    public function range(): LocalRange
    {
        $timezone = $this->timezone();

        return match ($this->preset) {
            self::PRESET_THIS_MONTH => LocalRange::month($timezone),
            self::PRESET_LAST_MONTH => LocalRange::month(
                $timezone,
                Carbon::now($timezone)->subMonthNoOverflow()->toDateString(),
            ),
            self::PRESET_THIS_YEAR => LocalRange::year($timezone),
            self::PRESET_CUSTOM => $this->customRange($timezone),
            default => LocalRange::lastDays(30, $timezone),
        };
    }

    /**
     * The operator's own two dates, with the bad halves filled in.
     *
     * A half-typed date is the normal state of a date field somebody is still
     * using, and a page that threw at that point would break while they typed.
     * An unreadable date falls back to the end of the default period rather
     * than to nothing.
     */
    private function customRange(string $timezone): LocalRange
    {
        $today = Carbon::now($timezone)->toDateString();

        $from = $this->validDate($this->from) ?? Carbon::parse($today)->subDays(29)->toDateString();
        $to = $this->validDate($this->to) ?? $today;

        return LocalRange::between($from, $to, $timezone);
    }

    private function validDate(string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1 ? trim($value) : null;
    }

    public function figures(): AnalyticsFigures
    {
        return AnalyticsFigures::forCurrentTenant();
    }

    /**
     * Everything the view draws, gathered in one pass.
     *
     * A blade that called the aggregator once per block would run the same
     * window through it a dozen times; the view gets a finished array and does
     * no arithmetic of its own.
     *
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $figures = $this->figures();
        $range = $this->range();
        $previous = $range->previous();
        $lastYear = $range->lastYear();

        $sales = $figures->sales($range);
        $revenue = $figures->revenue($range);

        return [
            'range' => $range,
            'grain' => $range->grain(),
            'revenue' => $revenue,
            'revenue_previous' => $figures->revenue($previous),
            'revenue_last_year' => $figures->revenue($lastYear),
            'sales' => $sales,
            'sales_previous' => $figures->sales($previous),
            'average' => $figures->averageBooking($range),
            'series' => $figures->series($range),
            'products' => $figures->byProduct($range),
            'vessels' => $figures->byVessel($range),
            'occupancy' => $figures->occupancy($range),
            'occupancy_by_month' => $figures->occupancyByMonth($range),
            'occupancy_by_product' => $figures->occupancyByProduct($range),
            'quiet' => $figures->quietSailings($range),
            'sources' => $figures->bySource($range),
            'campaigns' => $figures->byCampaign($range),
            'referrers' => $figures->byReferrer($range),
            'cancellations' => $figures->cancellations($range),
            'funnel' => $figures->funnel($range),
            'visits' => $figures->visits($range),
            'has_counts' => $figures->hasCounts($range),
            'has_test_bookings' => $figures->hasTestBookings(),
        ];
    }

    /** Cents, in the operator's own currency and language. */
    public function money(int $cents): string
    {
        return MoneyFormatter::format($cents, app()->getLocale(), MoneyFormatter::currency());
    }

    /**
     * A ratio as a percentage, in the operator's own language.
     *
     * Through ICU rather than `number_format`, which writes `0.4%` on a page
     * that writes `13.496,50 €` two lines above it — a Greek reader sees a
     * decimal point where their decimal separator is a comma, and reads it as
     * four hundred.
     *
     * One decimal below ten percent and none above: "0%" hides the difference
     * between an empty boat and a nearly empty one, and "43.7%" is precision
     * about a number that moves by a whole seat at a time.
     */
    public function percent(?float $ratio): ?string
    {
        if ($ratio === null) {
            return null;
        }

        $formatter = new NumberFormatter(Locales::icu(app()->getLocale()), NumberFormatter::PERCENT);
        $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, abs($ratio) < 0.1 ? 1 : 0);

        return (string) $formatter->format($ratio);
    }

    /**
     * The change from one figure to another, as a percentage.
     *
     * Null when there is nothing to compare against — a period that earned
     * nothing cannot be "up 100%", and a page that said so would be inventing
     * the most flattering number on it.
     */
    public function change(int $now, int $before): ?float
    {
        return $before === 0 ? null : ($now - $before) / abs($before);
    }
}
