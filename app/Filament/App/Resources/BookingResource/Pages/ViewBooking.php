<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\BookingResource\Pages;

use App\Domain\Booking\Actions\BuildQuote;
use App\Domain\Booking\Actions\CreateManualBooking;
use App\Domain\Booking\Actions\RecordManualPayment;
use App\Enums\BookingStatus;
use App\Enums\PaymentGatewayName;
use App\Filament\App\Resources\BookingResource;
use App\Filament\App\Resources\QuoteResource;
use App\Models\Booking;
use App\Models\Quote;
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

            /*
             * Writing the first offer — the entry point §4.4 assumed and
             * nothing provided.
             *
             * `BuildQuote` was reachable from exactly one place in the whole
             * application: **Revise**, on a quote that already existed. So an
             * operator could rewrite an offer and could not write one, and a
             * guest who asked for a price on a charter had their request sit in
             * `quote_requested` with no way forward from any screen. The
             * comment on `ListQuotes` said *"BuildQuote is reached from the
             * booking"*, which was true about the plan and not about the code.
             *
             * There is deliberately still no **Νέα προσφορά** button on the
             * quotes list: a quote belongs to a booking, and one created from a
             * list of quotes would be an orphan. It belongs here, where the
             * booking it is for is the record.
             */
            Action::make('build_quote')
                ->label(__('bookings.quote.build'))
                ->icon('heroicon-o-document-plus')
                // §4.4's own guard, asked before the button is drawn rather
                // than only when it is pressed: quoting a confirmed booking is
                // not a revision, it is a different conversation.
                ->visible(fn (): bool => $this->awaitingQuote() && $this->latestQuote() === null)
                ->action(function (): void {
                    $quote = app(BuildQuote::class)($this->booking(), Auth::id());

                    Notification::make()->success()->title(__('bookings.quote.built'))->send();

                    // Straight into the offer. The point of the button is the
                    // screen behind it, and a notification saying "created"
                    // with no way to reach it is the same dead end one level on.
                    $this->redirect(QuoteResource::getUrl('edit', ['record' => $quote]));
                }),

            /* The offer that already exists, rather than a second one. */
            Action::make('open_quote')
                ->label(__('bookings.quote.open'))
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->visible(fn (): bool => $this->latestQuote() !== null)
                ->url(fn (): string => QuoteResource::getUrl('edit', ['record' => $this->latestQuote()])),
        ];
    }

    /** §4.4: only a booking in quote mode may be quoted. */
    private function awaitingQuote(): bool
    {
        return in_array(
            $this->booking()->status,
            [BookingStatus::QuoteRequested, BookingStatus::QuoteSent],
            strict: true,
        ) && (Auth::user()?->hasCapability(Capability::ManageBookings) ?? false);
    }

    /** The newest version, which is the one an operator means by "the offer". */
    private function latestQuote(): ?Quote
    {
        return Quote::query()
            ->where('booking_id', $this->booking()->getKey())
            ->orderByDesc('version')
            ->first();
    }

    private function booking(): Booking
    {
        /** @var Booking $booking */
        $booking = $this->getRecord();

        return $booking;
    }
}
