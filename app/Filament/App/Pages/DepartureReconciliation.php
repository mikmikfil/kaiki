<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Domain\Availability\Support\DepartureReconciler;
use App\Exceptions\TenantContextMissingException;
use App\Models\ScheduleRule;
use App\Support\Tenancy;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;

/**
 * Where a schedule and its departures disagree (ADR-0009, ADR-0016).
 *
 * ADR-0009's Option A buys its safety with this page. Generation is additive
 * only — it never cancels a departure a rule stopped matching, and never lowers
 * the capacity of one that has been sold — which means the operator, not the
 * job, decides what to do about the difference. Without somewhere to see it,
 * "additive only" would just be a silent inconsistency.
 *
 * The list is derived rather than stored, so fixing a rule makes an entry
 * disappear on the next load without anything having to remember to delete it.
 *
 * A page has no model to hang a policy on, and Filament allows what nothing
 * forbids, so access is checked explicitly against the rule policy — this is a
 * view onto schedules, and crew have no business with it (TEN-8).
 */
class DepartureReconciliation extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?int $navigationSort = 35;

    protected static string $view = 'filament.app.pages.departure-reconciliation';

    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', ScheduleRule::class);
    }

    /**
     * Hidden when there is nothing wrong.
     *
     * A permanently visible "no problems" page is furniture, and an operator
     * who learns to ignore a menu item will ignore it on the day it matters.
     *
     * ## The tenant check is not redundant, and its absence is a panel-wide 500
     *
     * Filament calls this while building the **navigation**, which happens on
     * every page in the panel. `DepartureReconciler::all()` queries a
     * tenant-owned model, and `BelongsToTenant` **throws**
     * {@see TenantContextMissingException} rather than
     * returning nothing when no tenant is resolved (TEN-4).
     *
     * So on any request that reaches navigation without a resolved tenant, this
     * one predicate takes down every screen in `/app` — not only this page —
     * with an exception naming `ScheduleRule`, a model this page barely
     * mentions and that the operator was nowhere near. `canAccess()` does not
     * save it: a `Gate` check on a *class*, unlike one on a record, is answered
     * from the role matrix and never touches the database.
     *
     * The guard costs one null check and the failure it prevents is total, so
     * it goes first.
     */
    public static function shouldRegisterNavigation(): bool
    {
        if (! Tenancy::check()) {
            return false;
        }

        return self::canAccess() && DepartureReconciler::all()->isNotEmpty();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.catalogue');
    }

    public static function getNavigationLabel(): string
    {
        return __('availability.reconciliation.nav');
    }

    public function getTitle(): string
    {
        return __('availability.reconciliation.title');
    }

    /** @return array<int, array<string, string>> */
    public function getIssues(): array
    {
        return DepartureReconciler::all()
            ->map(static fn (array $issue): array => [
                'kind' => (string) __("availability.reconciliation.kinds.{$issue['kind']}"),
                'explanation' => (string) __("availability.reconciliation.explanations.{$issue['kind']}"),
                'product' => (string) $issue['product'],
                'local_date' => (string) $issue['local_date'],
                'detail' => (string) $issue['detail'],
            ])
            ->all();
    }
}
