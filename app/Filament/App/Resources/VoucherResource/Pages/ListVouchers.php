<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\VoucherResource\Pages;

use App\Domain\Pricing\Actions\IssueVoucher;
use App\Enums\VoucherReason;
use App\Filament\App\Resources\VoucherResource;
use App\Models\User;
use App\Models\Voucher;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * The list, and the one place a voucher is written out by hand (OPS-16).
 *
 * ## Issued through a modal, not a create page
 *
 * There is nothing to come back and edit, so a full page with a save button
 * would promise a second visit that never happens. The modal ends with the code
 * on screen and in a notification the operator can read out over the telephone,
 * which is usually what they are doing.
 *
 * ## Euros in, cents stored
 *
 * The form takes `12.50` because that is what an operator types. Everything
 * below {@see IssueVoucher} is integer cents (§1.5), and the conversion happens
 * once, here.
 */
class ListVouchers extends ListRecords
{
    protected static string $resource = VoucherResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('issue')
                ->label(__('vouchers.actions.issue.label'))
                ->icon('heroicon-o-plus')
                ->modalHeading(__('vouchers.actions.issue.heading'))
                ->modalDescription(__('vouchers.actions.issue.description'))
                ->modalSubmitActionLabel(__('vouchers.actions.issue.submit'))
                ->form(VoucherResource::issueSchema())
                ->authorize(fn (): bool => Auth::user()?->can('create', Voucher::class) ?? false)
                ->action(function (array $data): void {
                    /** @var User|null $actor */
                    $actor = Auth::user();

                    // Rounded, not cast. `(int) (12.10 * 100)` is 1209 on a
                    // binary float, and a voucher one cent short of what the
                    // operator typed is a discrepancy nobody can explain.
                    $cents = (int) round(((float) $data['amount']) * 100);

                    $expires = $data['expires_at'] === null || $data['expires_at'] === ''
                        ? null
                        // End of day, because PRC-21 evaluates expiry at the end
                        // of the day it names. Storing midnight would expire the
                        // voucher a day before the date printed on the email.
                        : Carbon::parse((string) $data['expires_at'])->endOfDay();

                    $voucher = app(IssueVoucher::class)->goodwill(
                        cents: $cents,
                        reason: VoucherReason::from((string) $data['reason']),
                        note: $data['notes'] === null || $data['notes'] === '' ? null : (string) $data['notes'],
                        expiresAt: $expires,
                        issuedByUserId: $actor?->getKey(),
                    );

                    if (! $voucher instanceof Voucher) {
                        Notification::make()
                            ->title(__('vouchers.actions.issue.too_small'))
                            ->danger()
                            ->send();

                        return;
                    }

                    // The code in the body, and persistent, because the operator
                    // is usually reading it to somebody as they press this.
                    Notification::make()
                        ->title(__('vouchers.actions.issue.done'))
                        ->body($voucher->code)
                        ->success()
                        ->persistent()
                        ->send();
                }),
        ];
    }
}
