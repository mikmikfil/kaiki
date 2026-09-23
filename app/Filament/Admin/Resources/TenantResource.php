<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Tenancy\Actions\StartImpersonation;
use App\Domain\Tenancy\Support\ImpersonationSession;
use App\Enums\Plan;
use App\Enums\TenantStatus;
use App\Enums\TenantVertical;
use App\Exceptions\ImpersonationRefused;
use App\Filament\Admin\Resources\TenantResource\Pages;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\TenantPolicy;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as AppDashboard;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
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
 * Pulled forward from M7 because `/admin` has been an empty panel since #9 and
 * the platform owner had no way to see their own operators. It arrived
 * read-only and is not read-only any more — see below.
 *
 * **This is the one resource in the application that is deliberately
 * cross-tenant.** `tenants` is not tenant-owned — it *is* the tenant — so
 * `BelongsToTenant` does not apply and no global scope narrows this query.
 * Everywhere else, a query returning two operators' rows is the defect #8
 * exists to catch; here it is the entire point, which is why
 * `TenantResourceTest` asserts two merchants appear rather than trusting it.
 *
 * **It writes now, and SEC-16 is what makes that allowed.** This resource was
 * read-only until ADR-0025 — named here as the blocker while it was undecided —
 * was accepted on 2026-09-04 and built by #53. The pages list below has three
 * entries: the list, `CreateTenant` for onboarding, and `EditTenant`.
 *
 * What guards the writes is not this class. {@see TenantPolicy} says only *who*
 * (a super admin; delete and force-delete stay refused outright). The three
 * things SEC-16 actually asks for — a confirmation, a typed reason, and an audit
 * row in the operator's own trail — belong to the act rather than to the actor,
 * so they live on {@see Pages\EditTenant} and are asserted in
 * `TenantResourceTest`. A field whose change must be recorded goes in that
 * page's `AUDITED` list; one that is not there changes silently.
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

                // Which operators board people at all, and which of those
                // scan. Both hidden by default: they are looked for, not
                // scanned down.
                IconColumn::make('check_in_enabled')
                    ->label(__('tenants.columns.check_in'))
                    ->state(fn (Tenant $record): bool => $record->usesCheckIn())
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('qr_check_in_enabled')
                    ->label(__('tenants.columns.qr_check_in'))
                    ->state(fn (Tenant $record): bool => $record->usesQrCheckIn())
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

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
            ->actions([EditAction::make(), static::impersonateAction()])
            ->bulkActions([]);
    }

    /**
     * «Σύνδεση ως» — TEN-7 and SAA-2 (Mike, 2026-09-23).
     *
     * On the merchant list rather than inside the edit screen, because this is
     * something done *to get to work*, not a change to the account: the person
     * reaching for it is usually answering «δεν μου βγάζει τιμή σε αυτή την
     * εκδρομή», and making them open a settings form first is a detour.
     *
     * ## Both answers are asked for before anything happens
     *
     * **Which person**, because an operator has several and what a session can
     * do depends entirely on which one it is — signing in as crew to debug an
     * owner's screen would fail in a way that looks like the bug being chased.
     * The list is that operator's own users, so the tenancy boundary is a
     * property of the options rather than a check on the answer.
     *
     * **Why**, required. SAA-2 wants the reason in the trail, and a box that
     * can be left empty is a box everybody leaves empty.
     *
     * The refusal comes back as a notification rather than a stack trace
     * (CNV-11): every sentence {@see ImpersonationRefused} carries is one a
     * super-admin can act on.
     */
    public static function impersonateAction(): Action
    {
        return Action::make('impersonate')
            ->label(__('tenants.impersonation.action.label'))
            ->icon('heroicon-m-arrow-right-end-on-rectangle')
            ->color('warning')
            ->modalHeading(__('tenants.impersonation.action.heading'))
            ->modalDescription(__('tenants.impersonation.action.description', [
                'minutes' => ImpersonationSession::MINUTES,
            ]))
            ->modalSubmitActionLabel(__('tenants.impersonation.action.submit'))
            ->form([
                Select::make('user_id')
                    ->label(__('tenants.impersonation.action.user.label'))
                    ->helperText(__('tenants.impersonation.action.user.help'))
                    ->options(static fn (Tenant $record): array => User::query()
                        ->where('tenant_id', $record->getKey())
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->required(),

                Textarea::make('reason')
                    ->label(__('tenants.impersonation.action.reason.label'))
                    ->helperText(__('tenants.impersonation.action.reason.help'))
                    ->rows(2)
                    ->required()
                    ->maxLength(500),
            ])
            // Hidden rather than merely refused on a soft-deleted operator: the
            // resolvers stop finding them, so the session would open onto a
            // panel with nothing in it.
            ->visible(static fn (Tenant $record): bool => $record->deleted_at === null)
            ->action(function (Tenant $record, array $data): void {
                $actor = auth()->user();
                $target = User::query()->find($data['user_id'] ?? null);

                if (! $actor instanceof User || ! $target instanceof User) {
                    return;
                }

                try {
                    app(StartImpersonation::class)($actor, $target, $record, (string) ($data['reason'] ?? ''));
                } catch (ImpersonationRefused $refused) {
                    Notification::make()->title($refused->getMessage())->danger()->send();

                    return;
                }

                redirect()->to(AppDashboard::getUrl(panel: 'app'));
            });
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
