<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\BookingResource\Pages;

use App\Domain\Booking\Actions\CreateManualBooking;
use App\Domain\Booking\Actions\RecordManualPayment;
use App\Enums\PaymentGatewayName;
use App\Filament\App\Resources\BookingResource;
use App\Models\Booking;
use App\Support\Authorization\Capability;
use App\Support\Format\MoneyFormatter;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

/**
 * One booking, read-only.
 *
 * A confirmed booking is not an editable record. Changing its party size, its
 * date or its total in a form would move seats, invalidate a price snapshot and
 * contradict a policy snapshot the guest was shown — each of which has an
 * Action that does it properly. Until those Actions have panel surfaces the
 * honest thing is a view rather than a form that half works.
 *
 * The **money section is absent for crew** rather than empty (TEN-8): a section
 * of blanks still tells somebody the numbers exist and that they are the person
 * not allowed to see them.
 */
class ViewBooking extends ViewRecord
{
    protected static string $resource = BookingResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        $money = static fn (int $state): string => MoneyFormatter::format(
            $state,
            app()->getLocale(),
            MoneyFormatter::currency(),
        );

        return $infolist->schema([
            Section::make(__('bookings.view.trip'))
                ->schema([
                    TextEntry::make('reference')->label(__('bookings.table.reference'))->copyable(),
                    TextEntry::make('product.title')->label(__('bookings.table.product')),
                    TextEntry::make('local_date')->label(__('bookings.table.date'))->date(),
                    TextEntry::make('local_time')->label(__('bookings.view.time')),
                    TextEntry::make('status')->label(__('bookings.table.status'))->badge(),
                    TextEntry::make('source')->label(__('bookings.table.source'))->badge(),
                ])
                ->columns(3),

            Section::make(__('bookings.view.guest'))
                ->schema([
                    TextEntry::make('guest_name')->label(__('bookings.table.guest')),
                    TextEntry::make('guest_email')->label(__('bookings.view.email'))->copyable(),
                    TextEntry::make('guest_phone')->label(__('bookings.view.phone'))->copyable(),
                    TextEntry::make('pax_total')->label(__('bookings.table.pax')),
                    TextEntry::make('special_requests')->label(__('bookings.view.special_requests'))->columnSpanFull(),
                ])
                ->columns(3),

            Section::make(__('bookings.view.money'))
                ->schema([
                    TextEntry::make('total_cents')->label(__('bookings.table.total'))->formatStateUsing($money),
                    TextEntry::make('paid_cents')->label(__('bookings.view.paid'))->formatStateUsing($money),
                    TextEntry::make('balance_cents')->label(__('bookings.view.balance'))->formatStateUsing($money),
                ])
                ->columns(3)
                ->visible(static fn (): bool => Auth::user()?->hasCapability(Capability::ViewFinancials) ?? false),
        ]);
    }

    /**
     * Recording money that arrived by hand (BKG-33, OPS-5).
     *
     * The ordinary case this page exists to serve: the phone call on Tuesday
     * and the cash on Saturday morning. {@see CreateManualBooking}
     * already handles the walk-up who pays as the booking is made; without
     * this, every other cash booking stayed unpaid in the system for ever, and
     * the dashboard's "owed to you" figure had nothing behind it.
     *
     * The amount defaults to the whole balance, which is what usually arrives,
     * and stays editable, because sometimes it is half of it now and the rest on
     * the day.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('record_payment')
                ->label(__('bookings.payment.action'))
                ->icon('heroicon-o-banknotes')
                ->visible(fn (): bool => $this->booking()->balance_cents > 0
                    && (Auth::user()?->hasCapability(Capability::ManageBookings) ?? false))
                ->form([
                    TextInput::make('amount')
                        ->label(__('bookings.payment.amount'))
                        ->numeric()
                        ->step('0.01')
                        ->minValue(0.01)
                        ->required()
                        ->default(fn (): float => $this->booking()->balance_cents / 100)
                        // Named on the field rather than only in the refusal: an
                        // operator should not have to be told off to find out
                        // what the number was supposed to be.
                        ->helperText(fn (): string => __('bookings.payment.owed', [
                            'amount' => MoneyFormatter::format(
                                $this->booking()->balance_cents,
                                app()->getLocale(),
                                MoneyFormatter::currency(),
                            ),
                        ])),
                    Select::make('gateway')
                        ->label(__('bookings.payment.how'))
                        ->options([
                            PaymentGatewayName::Cash->value => PaymentGatewayName::Cash->label(),
                            PaymentGatewayName::BankTransfer->value => PaymentGatewayName::BankTransfer->label(),
                        ])
                        ->default(PaymentGatewayName::Cash->value)
                        ->required(),
                    TextInput::make('reference')
                        ->label(__('bookings.payment.reference'))
                        ->maxLength(100)
                        ->helperText(__('bookings.payment.reference_help')),
                ])
                ->action(function (array $data): void {
                    app(RecordManualPayment::class)(
                        booking: $this->booking(),
                        // Euros in the form, cents everywhere else. `round`
                        // rather than a cast, because `(int) (1.15 * 100)` is
                        // 114 — a cent lost per booking, silently, for ever.
                        amountCents: (int) round(((float) $data['amount']) * 100),
                        gateway: PaymentGatewayName::from((string) $data['gateway']),
                        reference: ($data['reference'] ?? '') !== '' ? (string) $data['reference'] : null,
                        userId: Auth::id(),
                    );

                    Notification::make()->success()->title(__('bookings.payment.recorded'))->send();
                }),
        ];
    }

    private function booking(): Booking
    {
        /** @var Booking $booking */
        $booking = $this->getRecord();

        return $booking;
    }
}
