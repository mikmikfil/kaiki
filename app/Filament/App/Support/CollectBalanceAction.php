<?php

declare(strict_types=1);

namespace App\Filament\App\Support;

use App\Domain\Booking\Actions\CollectBalanceOnBoard;
use App\Enums\PaymentGatewayName;
use App\Models\Booking;
use App\Models\User;
use App\Support\Format\MoneyFormatter;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * «Πληρώθηκε» beside «Οφείλει €X», on the boarding list and on a booking crew
 * open (Mike, 2026-09-25; plan Β2).
 *
 * One builder for both places, so the modal asks the same single question in
 * the same words: cash or POS. There is no amount field — the amount is the
 * whole open balance, read by {@see CollectBalanceOnBoard} at the moment it is
 * recorded.
 */
final class CollectBalanceAction
{
    /**
     * @param  Closure(array<string, mixed>): ?Booking  $booking  the booking this press is about
     */
    public static function make(string $name, Closure $booking): Action
    {
        $amount = static fn (array $arguments): string => self::money($booking($arguments)->balance_cents ?? 0);

        return Action::make($name)
            ->label(__('checkin.balance.action'))
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->visible(static fn (array $arguments): bool => ($found = $booking($arguments)) instanceof Booking
                && CollectBalanceOnBoard::offeredTo(self::user(), $found))
            ->modalHeading(static fn (array $arguments): string => __('checkin.balance.heading', ['amount' => $amount($arguments)]))
            ->modalDescription(static fn (array $arguments): string => __('checkin.balance.description', [
                'reference' => $booking($arguments)->reference ?? '',
            ]))
            ->modalSubmitActionLabel(__('checkin.balance.confirm'))
            ->modalWidth('sm')
            ->form([
                Radio::make('gateway')
                    ->label(__('checkin.balance.how'))
                    ->options([
                        PaymentGatewayName::Cash->value => PaymentGatewayName::Cash->label(),
                        PaymentGatewayName::Pos->value => PaymentGatewayName::Pos->label(),
                    ])
                    // No default: which drawer the money went into is the one
                    // thing this modal asks, and a preselected answer is an
                    // answer nobody gave.
                    ->required(),
            ])
            ->action(static function (array $arguments, array $data) use ($booking): void {
                $found = $booking($arguments);
                $user = self::user();

                if (! $found instanceof Booking || ! $user instanceof User) {
                    return;
                }

                $gateway = PaymentGatewayName::from((string) $data['gateway']);
                $owed = $found->refresh()->balance_cents;

                try {
                    app(CollectBalanceOnBoard::class)($found, $gateway, $user);
                } catch (ValidationException $refused) {
                    Notification::make()->danger()->title((string) collect($refused->errors())->flatten()->first())->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('checkin.balance.done', ['amount' => self::money($owed), 'how' => $gateway->label()]))
                    ->send();
            });
    }

    /** «Οφείλει €X», as the list prints it. */
    public static function owes(Booking $booking): string
    {
        return __('checkin.balance.owes', ['amount' => self::money($booking->balance_cents)]);
    }

    private static function money(int $cents): string
    {
        return MoneyFormatter::format($cents, app()->getLocale(), MoneyFormatter::currency());
    }

    private static function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}
