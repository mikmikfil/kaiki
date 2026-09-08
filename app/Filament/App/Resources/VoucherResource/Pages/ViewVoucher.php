<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\VoucherResource\Pages;

use App\Enums\VoucherReason;
use App\Enums\VoucherStatus;
use App\Filament\App\Resources\VoucherResource;
use App\Models\Voucher;
use App\Support\Format\MoneyFormatter;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Carbon;

/**
 * One voucher, its terms, and the only thing that can be done to it.
 *
 * ## Cancelling is a status, not a deletion
 *
 * A cancelled voucher keeps its code, its amount and its ledger. Somebody was
 * told a number, and the record of that has to outlive the operator changing
 * their mind — a soft delete would take it off every screen that could answer
 * "what happened to the credit you promised me in July".
 *
 * ## A spent voucher cannot be cancelled
 *
 * There is nothing left to withdraw, and writing `cancelled` over `redeemed`
 * would erase the fact that a guest actually used it, which is the one thing
 * the row is evidence of.
 */
class ViewVoucher extends ViewRecord
{
    protected static string $resource = VoucherResource::class;

    /**
     * The code, rather than Filament's "Προεπισκόπηση Κουπόνι".
     *
     * The default composes a verb and a model label, which reads as awkward
     * Greek and tells an operator nothing they did not know from clicking. The
     * code is the thing they are about to read down a telephone.
     */
    public function getTitle(): string
    {
        /** @var Voucher $voucher */
        $voucher = $this->getRecord();

        return $voucher->code;
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('code')
                ->label(__('vouchers.table.code'))
                ->copyable()
                ->fontFamily('mono')
                ->weight('bold'),

            TextEntry::make('status')
                ->label(__('vouchers.table.status'))
                ->badge()
                ->formatStateUsing(fn (VoucherStatus $state): string => $state->label()),

            TextEntry::make('amount_cents')
                ->label(__('vouchers.table.amount'))
                ->formatStateUsing(fn (int $state, Voucher $record): string => MoneyFormatter::format(
                    $state,
                    app()->getLocale(),
                    $record->currency,
                )),

            TextEntry::make('remaining_cents')
                ->label(__('vouchers.table.remaining'))
                ->formatStateUsing(fn (int $state, Voucher $record): string => MoneyFormatter::format(
                    $state,
                    app()->getLocale(),
                    $record->currency,
                )),

            TextEntry::make('reason')
                ->label(__('vouchers.table.reason'))
                ->formatStateUsing(fn (VoucherReason $state): string => $state->label()),

            TextEntry::make('expires_at')
                ->label(__('vouchers.table.expires'))
                ->date()
                ->placeholder(__('vouchers.table.no_expiry')),

            TextEntry::make('issued_at')
                ->label(__('vouchers.table.issued'))
                ->dateTime(),

            TextEntry::make('issuedBy.name')
                ->label(__('vouchers.view.issued_by'))
                // Null when the person who wrote it has since left. The voucher
                // outlives the employment.
                ->placeholder(__('vouchers.view.issued_by_gone')),

            TextEntry::make('issued_for')
                ->label(__('vouchers.view.issued_for'))
                ->state(fn (Voucher $record): ?string => VoucherResource::issuedForLabel($record))
                ->placeholder(__('vouchers.view.issued_for_nobody')),

            TextEntry::make('notes')
                ->label(__('vouchers.form.notes.label'))
                ->placeholder('—')
                ->columnSpanFull(),
        ])->columns(2);
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('cancel')
                ->label(__('vouchers.actions.cancel.label'))
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (Voucher $record): bool => $record->status === VoucherStatus::Active)
                ->requiresConfirmation()
                ->modalHeading(__('vouchers.actions.cancel.heading'))
                // The consequence named rather than "are you sure": somebody is
                // holding this code and will try to use it.
                ->modalDescription(__('vouchers.actions.cancel.description'))
                ->action(function (): void {
                    /** @var Voucher $voucher */
                    $voucher = $this->getRecord();

                    $voucher->forceFill([
                        'status' => VoucherStatus::Cancelled,
                        'updated_at' => Carbon::now(),
                    ])->save();

                    Notification::make()
                        ->title(__('vouchers.actions.cancel.done'))
                        ->success()
                        ->send();
                }),
        ];
    }
}
