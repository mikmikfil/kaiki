<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets;

use App\Domain\Operations\Support\DashboardFigures;
use App\Domain\Operations\Support\FirstSteps;
use App\Filament\App\Resources\BookingResource;
use App\Filament\App\Resources\DepartureResource;
use App\Filament\App\Resources\QuoteResource;
use App\Support\Authorization\Capability;
use App\Support\Format\MoneyFormatter;
use App\Support\Tenancy;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * The operator's first screen (spec OPS-1, OPS-2).
 *
 * Six figures, and every one of them is a link. A dashboard number nobody can
 * click is a number that makes an operator go and find the list themselves,
 * which is the moment they stop using the dashboard.
 *
 * ## The definition is on the screen, not only in the docblock
 *
 * OPS-2. Each stat's description says what it counts, in the operator's own
 * language. This looks like clutter until the first time somebody reconciles
 * "revenue this week" against their bank statement: if the two disagree and the
 * screen has not said what it counted, they conclude the product is wrong about
 * money, and they are right to.
 *
 * The arithmetic itself is in {@see DashboardFigures} — one implementation, one
 * set of tests, and nothing here that could quietly compute a seventh answer.
 */
class OperationsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    /**
     * Hidden until there is something to count.
     *
     * Six zeros and a link to an empty list is what this shows on an operator's
     * first afternoon, and it is a product that looks broken on the day somebody
     * decides whether to keep paying for it. {@see \App\Filament\App\Widgets\FirstSteps}
     * takes the space instead, and the two conditions are opposites of one
     * predicate so they can never both appear or both vanish.
     */
    public static function canView(): bool
    {
        // With no tenant resolved, `applies()` answers false and the negation
        // would show six figures that cannot be counted — `DashboardFigures`
        // queries tenant-owned models and `BelongsToTenant` throws rather than
        // returning nothing (TEN-4). The guard is the widget's own, because a
        // predicate that depends on the *negation* of somebody else's guard
        // inverts the moment that guard becomes defensive.
        if (! Tenancy::check()) {
            return false;
        }

        return ! FirstSteps::applies();
    }

    protected function getColumns(): int
    {
        return 3;
    }

    /**
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        $figures = DashboardFigures::forCurrentTenant();

        $sailing = $figures->todayAndTomorrow();

        $stats = [
            Stat::make(
                __('dashboard.sailing.label'),
                (string) $sailing['departures'],
            )
                ->description(__('dashboard.sailing.definition', ['pax' => $sailing['pax']]))
                ->descriptionIcon('heroicon-m-calendar-days')
                ->url(DepartureResource::getUrl('index')),

            Stat::make(
                __('dashboard.at_risk.label'),
                (string) $figures->atRiskDepartures(),
            )
                ->description(__('dashboard.at_risk.definition', ['hours' => DashboardFigures::AT_RISK_HOURS]))
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->url(DepartureResource::getUrl('index')),

            Stat::make(
                __('dashboard.guest_details.label'),
                (string) $figures->pendingGuestDetails(),
            )
                ->description(__('dashboard.guest_details.definition'))
                ->descriptionIcon('heroicon-m-identification')
                ->url(BookingResource::getUrl('index')),

            Stat::make(
                __('dashboard.quotes.label'),
                (string) $figures->pendingQuotes(),
            )
                ->description(__('dashboard.quotes.definition'))
                ->descriptionIcon('heroicon-m-envelope')
                ->url(QuoteResource::getUrl('index')),
        ];

        /*
         * The two money figures, and only for somebody allowed to see money.
         *
         * TEN-8 gives crew departures, the pax list, check-in and the manifest,
         * and says **no pricing, no financials**. This widget had no capability
         * check at all, and the dashboard is the panel's landing page — so a
         * skipper signing in to look at today's sailings was shown the
         * operator's outstanding balances and the week's takings, on the first
         * screen, every time. Found while writing the manual's chapter on which
         * role sees what, which is the chapter that had to state it in a table
         * and could not.
         *
         * The other four figures stay: how many departures, how many at risk,
         * how many bookings still owe passenger details, how many quotes are
         * unanswered. None of those is pricing, and all of them are things the
         * person on the boat has a reason to know.
         *
         * Appended rather than filtered out of a list of six, so a figure that
         * needs financial access cannot be added later without being put here.
         */
        if (Auth::user()?->hasCapability(Capability::ViewFinancials) !== true) {
            return $stats;
        }

        $owed = $figures->unpaidBalances();
        $week = $figures->week();

        $stats[] = Stat::make(
            __('dashboard.balances.label'),
            MoneyFormatter::format($owed['cents'], app()->getLocale(), MoneyFormatter::currency()),
        )
            ->description(__('dashboard.balances.definition', ['count' => $owed['bookings']]))
            ->descriptionIcon('heroicon-m-banknotes')
            ->url(BookingResource::getUrl('index'));

        $stats[] = Stat::make(
            __('dashboard.revenue.label'),
            MoneyFormatter::format($figures->revenueThisWeek(), app()->getLocale(), MoneyFormatter::currency()),
        )
            ->description(__('dashboard.revenue.definition', ['from' => $week->startLocalDate]))
            ->descriptionIcon('heroicon-m-chart-bar');

        return $stats;
    }

    /**
     * The disclaimer, and only when it applies.
     *
     * Every figure above excludes `is_test` bookings. Saying so permanently on
     * a site that has none is noise, and noise is what teaches people to stop
     * reading the small print on a dashboard — so the line appears exactly when
     * there is something not being counted.
     */
    protected function getHeading(): ?string
    {
        return DashboardFigures::forCurrentTenant()->hasTestBookings()
            ? __('dashboard.excludes_test')
            : null;
    }
}
