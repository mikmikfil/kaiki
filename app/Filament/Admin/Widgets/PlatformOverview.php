<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * The shape of the platform, at a glance (spec SAA-1).
 *
 * Four counters on the `/admin` landing page, so the first thing the platform
 * owner sees is how many operators there are and how many are paying. It is the
 * smallest thing that makes `/admin` a dashboard rather than an empty page.
 *
 * **One grouped query, not four counts.** This renders on every `/admin` page
 * load, and four `COUNT(*)` round trips for a four-number summary is the kind
 * of cost that is invisible at ten operators and obvious at a thousand.
 *
 * Soft-deleted operators are excluded everywhere: a cancelled account is not a
 * merchant, and counting it would make "Merchants" disagree with the list
 * directly beneath it — which is worse than either number alone.
 */
class PlatformOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    /**
     * Rendered inline rather than lazily.
     *
     * Filament defers widgets to a second Livewire request by default, which
     * earns its keep for an expensive chart and costs a round trip and a
     * layout shift for four integers from one grouped query. This is also the
     * first thing on the first screen, so it is the worst place in the panel
     * for numbers that pop in a moment late.
     */
    protected static bool $isLazy = false;

    /** @return array<int, Stat> */
    protected function getStats(): array
    {
        $byStatus = self::countsByStatus();
        $total = array_sum($byStatus);

        return [
            Stat::make(__('tenants.overview.total'), (string) $total)
                ->description(__('tenants.overview.total_description'))
                ->icon('heroicon-o-building-storefront'),

            Stat::make(
                __('tenants.overview.active'),
                (string) ($byStatus[TenantStatus::Active->value] ?? 0),
            )
                ->description(__('tenants.overview.active_description'))
                ->color('success'),

            Stat::make(
                __('tenants.overview.trialing'),
                (string) ($byStatus[TenantStatus::Trialing->value] ?? 0),
            )
                ->description(__('tenants.overview.trialing_description'))
                ->color('info'),

            Stat::make(
                __('tenants.overview.past_due'),
                (string) ($byStatus[TenantStatus::PastDue->value] ?? 0),
            )
                ->description(__('tenants.overview.past_due_description'))
                ->color('warning'),
        ];
    }

    /**
     * status => count, for live operators only.
     *
     * Public and static so the counting can be asserted directly. Filament's
     * `getCachedStats()` is protected, and reaching through it with reflection
     * would test the framework's caching rather than our arithmetic.
     *
     * @return array<string, int>
     */
    public static function countsByStatus(): array
    {
        /** @var array<string, int> $counts */
        $counts = Tenant::query()
            ->toBase()
            ->select('status', DB::raw('count(*) as aggregate'))
            ->whereNull('deleted_at')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();

        return $counts;
    }
}
