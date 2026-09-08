<?php

declare(strict_types=1);

namespace App\Filament\App\Resources;

use App\Domain\Pricing\Actions\IssueVoucher;
use App\Enums\VoucherReason;
use App\Enums\VoucherStatus;
use App\Filament\App\Resources\VoucherResource\Pages;
use App\Filament\App\Resources\VoucherResource\RelationManagers\RedemptionsRelationManager;
use App\Models\Booking;
use App\Models\Voucher;
use App\Support\Format\MoneyFormatter;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The vouchers an operator has issued (spec OPS-16).
 *
 * Thin by design (CNV-5). The arithmetic is PRC-18…22 and lives in
 * {@see IssueVoucher}, `ApplyVoucher` and `RestoreVoucher`; the ledger is
 * `voucher_redemptions`. This class decides what an operator sees.
 *
 * ## Everything here already worked; none of it could be seen
 *
 * Vouchers have been issued automatically since M2 — a weather cancellation
 * produces one, a refund restores one, the widget accepts the code, and the
 * guest has a page at `/v/{code}`. What did not exist was any way for the
 * operator to **look**: no list, no search by code, and no way to write one out
 * by hand. `VoucherReason::Goodwill` and `::Manual` have been in the enum since
 * M2 with no caller at all.
 *
 * ## Read-mostly, and deliberately so
 *
 * There is no edit form. A voucher's amount, its code and its expiry are terms
 * somebody was given — often in writing, in an email they still have — and a
 * screen that let an operator quietly change the number would make every one of
 * those emails a liability. Two things can be done to an existing voucher:
 * cancel it, which is recorded, and read it.
 *
 * The remaining balance is not editable either. It is the sum of the ledger,
 * rebuilt by `ApplyVoucher`, and a hand-set figure would disagree with the
 * redemptions listed directly beneath it.
 */
class VoucherResource extends Resource
{
    protected static ?string $model = Voucher::class;

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    /** Beside the bookings and the quotes: it is money owed to a guest. */
    protected static ?int $navigationSort = 23;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('vouchers.nav');
    }

    public static function getModelLabel(): string
    {
        return __('vouchers.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('vouchers.plural');
    }

    /**
     * The issue form, used only by the create action on the list.
     *
     * @return array<int, Component>
     */
    public static function issueSchema(): array
    {
        return [
            TextInput::make('amount')
                ->label(__('vouchers.form.amount.label'))
                ->helperText(__('vouchers.form.amount.help'))
                ->numeric()
                ->minValue(0.01)
                ->step(0.01)
                ->required()
                ->prefix('€'),

            Select::make('reason')
                ->label(__('vouchers.form.reason.label'))
                ->helperText(__('vouchers.form.reason.help'))
                ->options([
                    // Only the two an operator issues by hand. The other three
                    // are written by the cancellation machinery and mean
                    // something specific about how a booking ended; offering
                    // them here would let a goodwill credit claim to be a
                    // weather cancellation in every report that groups by
                    // reason.
                    VoucherReason::Goodwill->value => VoucherReason::Goodwill->label(),
                    VoucherReason::Manual->value => VoucherReason::Manual->label(),
                ])
                ->default(VoucherReason::Goodwill->value)
                ->native(false)
                ->required(),

            DatePicker::make('expires_at')
                ->label(__('vouchers.form.expires_at.label'))
                ->helperText(__('vouchers.form.expires_at.help'))
                ->default(now()->addMonths(IssueVoucher::goodwillMonths()))
                ->minDate(now()->addDay())
                // Clearable on purpose: "whenever you like" is a real answer,
                // and a null expiry never ages out.
                ->native(false),

            Textarea::make('notes')
                ->label(__('vouchers.form.notes.label'))
                ->helperText(__('vouchers.form.notes.help'))
                ->maxLength(500)
                ->rows(2),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label(__('vouchers.table.code'))
                    ->searchable()
                    ->copyable()
                    ->copyMessage(__('vouchers.table.copied'))
                    ->fontFamily('mono'),

                TextColumn::make('amount_cents')
                    ->label(__('vouchers.table.amount'))
                    ->formatStateUsing(fn (int $state, Voucher $record): string => MoneyFormatter::format(
                        $state,
                        app()->getLocale(),
                        $record->currency,
                    ))
                    ->sortable(),

                TextColumn::make('remaining_cents')
                    ->label(__('vouchers.table.remaining'))
                    ->formatStateUsing(fn (int $state, Voucher $record): string => MoneyFormatter::format(
                        $state,
                        app()->getLocale(),
                        $record->currency,
                    ))
                    // Grey once it is spent: a zero balance is the ordinary end
                    // of a voucher's life, not a problem.
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray')
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('vouchers.table.status'))
                    ->badge()
                    ->formatStateUsing(fn (VoucherStatus $state): string => $state->label())
                    ->color(fn (VoucherStatus $state): string => match ($state) {
                        VoucherStatus::Active => 'success',
                        VoucherStatus::Redeemed => 'gray',
                        VoucherStatus::Expired => 'warning',
                        VoucherStatus::Cancelled => 'danger',
                    }),

                TextColumn::make('reason')
                    ->label(__('vouchers.table.reason'))
                    ->formatStateUsing(fn (VoucherReason $state): string => $state->label())
                    ->toggleable(),

                TextColumn::make('expires_at')
                    ->label(__('vouchers.table.expires'))
                    ->date()
                    ->placeholder(__('vouchers.table.no_expiry'))
                    ->sortable(),

                TextColumn::make('issued_at')
                    ->label(__('vouchers.table.issued'))
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('issued_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('vouchers.table.status'))
                    ->options(VoucherStatus::options()),

                SelectFilter::make('reason')
                    ->label(__('vouchers.table.reason'))
                    ->options(VoucherReason::options()),

                TernaryFilter::make('spendable')
                    ->label(__('vouchers.filters.spendable'))
                    // The question an operator on the telephone is actually
                    // asking: "can this person use this code right now". Three
                    // conditions, and the status alone answers none of them.
                    ->queries(
                        true: fn (Builder $query): Builder => $query
                            ->where('status', VoucherStatus::Active)
                            ->where('remaining_cents', '>', 0)
                            ->where(fn (Builder $q): Builder => $q
                                ->whereNull('expires_at')
                                ->orWhere('expires_at', '>=', now())),
                        false: fn (Builder $query): Builder => $query
                            ->where(fn (Builder $q): Builder => $q
                                ->whereNot('status', VoucherStatus::Active)
                                ->orWhere('remaining_cents', '<=', 0)
                                ->orWhere('expires_at', '<', now())),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->emptyStateHeading(__('vouchers.empty.heading'))
            ->emptyStateDescription(__('vouchers.empty.description'));
    }

    /** @return array<int, class-string> */
    public static function getRelations(): array
    {
        return [RedemptionsRelationManager::class];
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVouchers::route('/'),
            'view' => Pages\ViewVoucher::route('/{record}'),
        ];
    }

    /**
     * The booking a voucher was issued against, as one line.
     *
     * `issuedForBooking()` is a method rather than a relation — there is no
     * foreign key, because `vouchers` is the weak side of a cycle with
     * `bookings` (§2.5) — so this cannot be an ordinary eager-loaded column and
     * is written out here instead.
     */
    public static function issuedForLabel(Voucher $voucher): ?string
    {
        $booking = $voucher->issuedForBooking();

        return $booking instanceof Booking ? $booking->reference : null;
    }
}
