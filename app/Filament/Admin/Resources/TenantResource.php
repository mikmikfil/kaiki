<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\Plan;
use App\Enums\TenantStatus;
use App\Enums\TenantVertical;
use App\Filament\Admin\Resources\TenantResource\Pages;
use App\Models\Tenant;
use App\Policies\TenantPolicy;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * The platform's merchant list (spec SAA-1, SCP-13).
 *
 * The read-only half of SAA-1, pulled forward from M7 because `/admin` has been
 * an empty panel since #9 and the platform owner has had no way to see their
 * own operators.
 *
 * **This is the one resource in the application that is deliberately
 * cross-tenant.** `tenants` is not tenant-owned — it *is* the tenant — so
 * `BelongsToTenant` does not apply and no global scope narrows this query.
 * Everywhere else, a query returning two operators' rows is the defect #8
 * exists to catch; here it is the entire point, which is why
 * `TenantResourceTest` asserts two merchants appear rather than trusting it.
 *
 * **Read-only on purpose, and it must stay that way for now.** The moment this
 * screen can change an operator's record, SEC-16 applies and #42's undecided
 * ADR-0025 becomes a blocker. {@see TenantPolicy} refuses every
 * write, and the pages list below has exactly one entry.
 */
class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return __('tenants.nav');
    }

    public static function getModelLabel(): string
    {
        return __('tenants.model.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('tenants.model.plural');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('tenants.columns.name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('slug')
                    ->label(__('tenants.columns.slug'))
                    ->searchable()
                    // The hosted page address, which is what support is usually
                    // given when an operator describes a problem.
                    ->copyable()
                    ->toggleable(),

                TextColumn::make('plan')
                    ->label(__('tenants.columns.plan'))
                    ->badge()
                    // Through the enum, not a second copy of these strings:
                    // #12 consolidated every label into `enums.php` so the two
                    // panels cannot disagree about what a plan is called.
                    ->formatStateUsing(fn (Plan $state): string => $state->label())
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('tenants.columns.status'))
                    ->badge()
                    ->formatStateUsing(fn (TenantStatus $state): string => $state->label())
                    ->color(fn (TenantStatus $state): string => match ($state) {
                        TenantStatus::Active => 'success',
                        TenantStatus::Trialing => 'info',
                        TenantStatus::PastDue => 'warning',
                        TenantStatus::ReadOnly, TenantStatus::Suspended => 'danger',
                    })
                    ->sortable(),

                TextColumn::make('vertical')
                    ->label(__('tenants.columns.vertical'))
                    ->formatStateUsing(fn (?TenantVertical $state): string => $state?->label() ?? '—')
                    ->toggleable(),

                // Days rather than a date, because the question this list is
                // opened for is "who lapses this week" and a date makes the
                // reader do the arithmetic on every row.
                TextColumn::make('access_days_left')
                    ->label(__('tenants.columns.days_left'))
                    ->state(fn (Tenant $record): ?int => $record->accessDaysLeft())
                    ->badge()
                    ->formatStateUsing(fn (?int $state): string => match (true) {
                        $state === null => __('tenants.days.none'),
                        $state < 0 => __('tenants.days.lapsed', ['days' => abs($state)]),
                        default => __('tenants.days.left', ['days' => $state]),
                    })
                    ->color(fn (?int $state): string => match (true) {
                        $state === null => 'gray',
                        $state < 0 => 'danger',
                        $state <= 7 => 'warning',
                        default => 'success',
                    }),

                TextColumn::make('default_locale')
                    ->label(__('tenants.columns.locale'))
                    ->formatStateUsing(fn (string $state): string => __("enums.locale.{$state}.short"))
                    ->toggleable(),

                TextColumn::make('vat_number')
                    ->label(__('tenants.columns.vat_number'))
                    // Indexed for exactly this (data-model §2.1); public company
                    // data, so not encrypted and safe to show.
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label(__('tenants.columns.joined'))
                    ->date()
                    ->sortable(),

                TextColumn::make('deleted_at')
                    ->label(__('tenants.columns.deleted'))
                    ->date()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('tenants.filters.status'))
                    ->options(TenantStatus::options()),

                SelectFilter::make('plan')
                    ->label(__('tenants.filters.plan'))
                    ->options(Plan::options()),

                SelectFilter::make('vertical')
                    ->label(__('tenants.columns.vertical'))
                    ->options(TenantVertical::options()),

                // The one list the platform owner opens on a Monday. A filter
                // rather than a sort, because the answer is usually "nobody"
                // and a sorted table still shows forty rows to say so.
                Filter::make('lapsing')
                    ->label(__('tenants.filters.lapsing'))
                    ->query(fn (Builder $query): Builder => $query->where(
                        fn (Builder $inner): Builder => $inner
                            ->whereBetween('subscription_ends_at', [now()->startOfDay(), now()->addDays(7)->endOfDay()])
                            ->orWhere(
                                fn (Builder $trial): Builder => $trial
                                    ->whereNull('subscription_ends_at')
                                    ->whereBetween('trial_ends_at', [now()->startOfDay(), now()->addDays(7)->endOfDay()]),
                            ),
                    )),

                // Defaults to hiding trashed. A deleted operator's data still
                // exists and the platform owner is the one person who may need
                // to see it — but not by default, or the list stops meaning
                // "our merchants".
                TrashedFilter::make()
                    ->label(__('tenants.filters.trashed')),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__('tenants.empty.heading'))
            ->emptyStateDescription(__('tenants.empty.description'))
            // One row action and no bulk actions. Editing an operator is a
            // deliberate act with a reason attached (SEC-16); a bulk version of
            // that would be one reason covering forty accounts, which is the
            // same as no reason.
            ->actions([EditAction::make()])
            ->bulkActions([]);
    }

    /**
     * Soft-deleted rows are reachable through the filter, not by default.
     *
     * The scope is removed here so `TrashedFilter` can put it back — that is
     * Filament's mechanism, and the filter's own default state is "without
     * trashed", so the list still opens showing live merchants only.
     *
     * @return Builder<Tenant>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    /**
     * Three pages: the list, taking on a customer, and the five fields the
     * platform may change afterwards.
     *
     * Creating goes through `OnboardOperator` rather than writing a row —
     * an operator is a tenant, a brand profile and an invited owner, and a bare
     * insert makes a business nobody can sign in to. `TenantResourceTest`
     * asserts the list stays at exactly these three.
     *
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTenants::route('/'),
            'create' => Pages\CreateTenant::route('/create'),
            'edit' => Pages\EditTenant::route('/{record}/edit'),
        ];
    }
}
