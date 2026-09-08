<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\VoucherResource\RelationManagers;

use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Support\Format\MoneyFormatter;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The ledger, which is where the remaining balance actually comes from
 * (spec PRC-18, OPS-16).
 *
 * ## It is read-only, and that is the point of showing it
 *
 * `vouchers.remaining_cents` is not a number anybody types. It is rebuilt from
 * these rows by `ApplyVoucher`, so this table is the arithmetic behind the
 * figure on the screen above it — an operator asked "why does it say forty
 * euros when I gave them a hundred" can read the answer rather than be told it.
 *
 * ## A reversal is a column, not a deleted row
 *
 * A refund gives value back to a voucher, and the redemption that value came
 * from still happened. Deleting the row would make the ledger stop adding up to
 * the balance; `reversed_amount_cents` beside the original is what keeps
 * `Σ(amount − reversed)` true and what lets the screen show a booking that was
 * paid with credit and then cancelled.
 */
class RedemptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'redemptions';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('vouchers.redemptions.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('redeemed_at')
                    ->label(__('vouchers.redemptions.when'))
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('booking.reference')
                    ->label(__('vouchers.redemptions.booking'))
                    ->fontFamily('mono')
                    ->placeholder('—'),

                TextColumn::make('amount_cents')
                    ->label(__('vouchers.redemptions.amount'))
                    ->formatStateUsing(fn (int $state, VoucherRedemption $record): string => MoneyFormatter::format(
                        $state,
                        app()->getLocale(),
                        $record->voucher->currency,
                    )),

                TextColumn::make('reversed_amount_cents')
                    ->label(__('vouchers.redemptions.reversed'))
                    // Nothing rather than "0,00 €" in the ordinary case: a
                    // column of zeros invites somebody to read a normal ledger
                    // as a list of reversals.
                    ->formatStateUsing(fn (int $state, VoucherRedemption $record): string => $state === 0
                        ? '—'
                        : MoneyFormatter::format($state, app()->getLocale(), $record->voucher->currency))
                    ->color(fn (int $state): string => $state === 0 ? 'gray' : 'warning'),

                TextColumn::make('reason')
                    ->label(__('vouchers.redemptions.reason'))
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('redeemed_at', 'desc')
            ->emptyStateHeading(__('vouchers.redemptions.empty'))
            ->emptyStateDescription(__('vouchers.redemptions.empty_description'))
            // Nothing here is created, edited or deleted by hand. The ledger is
            // written by the pricing Actions, and a row an operator could type
            // would be a balance that disagrees with the bookings it came from.
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
