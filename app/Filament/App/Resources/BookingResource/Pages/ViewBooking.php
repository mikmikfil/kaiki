<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\BookingResource\Pages;

use App\Domain\Booking\Actions\BuildQuote;
use App\Domain\Booking\Actions\CancelBooking;
use App\Domain\Booking\Actions\CreateManualBooking;
use App\Domain\Booking\Actions\RecordManualPayment;
use App\Domain\Booking\Actions\RemoveGuestsFromBooking;
use App\Domain\Booking\Data\RefundOverride;
use App\Domain\Booking\Support\RefundEntitlement;
use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Enums\CancelledBy;
use App\Enums\CancelReason;
use App\Enums\PaymentGatewayName;
use App\Enums\RefundMethod;
use App\Events\BookingCancelledByOperator;
use App\Events\BookingGuestsRemoved;
use App\Filament\App\Resources\BookingResource;
use App\Filament\App\Resources\QuoteResource;
use App\Models\Booking;
use App\Models\Quote;
use App\Support\Authorization\Capability;
use App\Support\Format\MoneyFormatter;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * One booking, read-only.
 *
 * A confirmed booking is not an editable record. Changing its party size, its
 * date or its total in a form would move seats, invalidate a price snapshot and
 * contradict a policy snapshot the guest was shown — each of which has an
 * Action that does it properly. So the page stays a view, and the changes an
 * operator does make are header actions that call those Actions: record a
 * payment, remove people, cancel.
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

            $this->removeGuestsAction(),

            $this->cancelAction(),

            /* The offer that already exists, rather than a second one. */
            Action::make('open_quote')
                ->label(__('bookings.quote.open'))
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->visible(fn (): bool => $this->latestQuote() !== null)
                ->url(fn (): string => QuoteResource::getUrl('edit', ['record' => $this->latestQuote()])),
        ];
    }

    /**
     * «Ακύρωση κράτησης» — the phone call that ends a booking (2026-09-17).
     *
     * Until now only the guest's own link, the API or cancelling the whole
     * departure could end a booking, so an operator told "we can't come" on
     * the phone had no button. The rules did not move: {@see CancelBooking}
     * releases the seats and settles, and the email goes out from its event.
     *
     * The form asks the two questions the refund depends on, in that order:
     *
     * 1. **Who asked.** A guest changing their mind is what the cancellation
     *    policy is for. When the operator is the reason, the default is
     *    everything back, the same answer a cancelled departure now gives.
     * 2. **How much back.** The policy's figure, everything, another percentage,
     *    or a voucher. Anything other than the policy — or «everything» when
     *    the guest asked — is an override (CXL-5) and needs a reason, which
     *    {@see RefundOverride} refuses to be built without.
     */
    private function cancelAction(): Action
    {
        return Action::make('cancel_booking')
            ->label(__('bookings.cancel.action'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (): bool => $this->booking()->status->isLive()
                && $this->booking()->status->canTransitionTo(BookingStatus::Cancelled)
                && (Auth::user()?->hasCapability(Capability::ManageBookings) ?? false))
            ->modalHeading(fn (): string => __('bookings.cancel.heading', ['reference' => $this->booking()->reference]))
            ->modalDescription(function (): string {
                $policy = RefundEntitlement::forCancellation($this->booking());

                return __('bookings.cancel.paid', [
                    'amount' => $this->euros($this->booking()->paid_cents),
                    'percent' => $policy->percent,
                    'refund' => $this->euros($policy->totalCents),
                ]) . ' ' . __('bookings.cancel.effects');
            })
            ->modalSubmitActionLabel(__('bookings.cancel.confirm'))
            ->form([
                Radio::make('who')
                    ->label(__('bookings.cancel.who.label'))
                    ->options([
                        'guest' => __('bookings.cancel.who.guest'),
                        'operator' => __('bookings.cancel.who.operator'),
                    ])
                    ->default('guest')
                    ->live()
                    ->afterStateUpdated(static fn (Set $set, ?string $state) => $set('refund', $state === 'operator' ? 'full' : 'policy'))
                    ->required(),
                Radio::make('refund')
                    ->label(__('bookings.cancel.refund.label'))
                    ->options(fn (): array => [
                        'policy' => __('bookings.cancel.refund.policy', [
                            'percent' => RefundEntitlement::forCancellation($this->booking())->percent,
                            'amount' => $this->euros(RefundEntitlement::forCancellation($this->booking())->totalCents),
                        ]),
                        'full' => __('bookings.cancel.refund.full', [
                            'amount' => $this->euros(RefundEntitlement::atPercent($this->booking(), 100)->totalCents),
                        ]),
                        'percent' => __('bookings.cancel.refund.percent'),
                        'voucher' => __('bookings.cancel.refund.voucher'),
                    ])
                    ->default('policy')
                    ->live()
                    ->required(),
                TextInput::make('percent')
                    ->label(__('bookings.cancel.percent'))
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->maxValue(100)
                    ->suffix('%')
                    ->default(100)
                    ->visible(static fn (Get $get): bool => in_array($get('refund'), ['percent', 'voucher'], true))
                    ->required(static fn (Get $get): bool => in_array($get('refund'), ['percent', 'voucher'], true)),
                Textarea::make('reason')
                    ->label(__('bookings.cancel.reason'))
                    ->helperText(__('bookings.cancel.reason_help'))
                    ->rows(2)
                    ->maxLength(500)
                    ->required(static fn (Get $get): bool => self::isOverride((string) $get('who'), (string) $get('refund'))),
            ])
            ->action(function (array $data): void {
                $this->cancelWith(
                    (string) $data['who'],
                    (string) $data['refund'],
                    (int) ($data['percent'] ?? 100),
                    trim((string) ($data['reason'] ?? '')),
                );
            });
    }

    /**
     * «Αφαίρεση ατόμων» — four booked, three coming (2026-09-17).
     *
     * One counter per age band on the booking, with the booked number beside
     * it, and a live line underneath saying what the change does to the total
     * and the money — the same {@see RemoveGuestsFromBooking::preview()} the
     * removal itself runs, so the sentence cannot promise a different figure.
     * The rules (a minimum party, a child never left without an adult) are the
     * Action's and come back as the form's error.
     */
    private function removeGuestsAction(): Action
    {
        return Action::make('remove_guests')
            ->label(__('bookings.remove_guests.action'))
            ->icon('heroicon-o-user-minus')
            ->color('gray')
            ->visible(fn (): bool => $this->booking()->mode === BookingMode::PerSeat
                && in_array($this->booking()->status, [BookingStatus::Confirmed, BookingStatus::PendingPayment], true)
                && $this->booking()->pax_total > 1
                && (Auth::user()?->hasCapability(Capability::ManageBookings) ?? false))
            ->modalHeading(fn (): string => __('bookings.remove_guests.heading', ['reference' => $this->booking()->reference]))
            ->modalDescription(__('bookings.remove_guests.description'))
            ->modalSubmitActionLabel(__('bookings.remove_guests.confirm'))
            ->form(fn (): array => [
                ...array_map(
                    fn (array $line): TextInput => TextInput::make('remove.' . $line['code'])
                        ->label(__('bookings.remove_guests.band', [
                            'label' => $this->bandLabel($line),
                            'qty' => (int) $line['qty'],
                        ]))
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->maxValue((int) $line['qty'])
                        ->default(0)
                        ->live(debounce: 300),
                    array_values($this->booking()->pax_breakdown),
                ),
                Placeholder::make('remove_preview')
                    ->hiddenLabel()
                    ->content(fn (Get $get): string => $this->removalSentence((array) ($get('remove') ?? []))),
                Textarea::make('reason')
                    ->label(__('bookings.remove_guests.reason'))
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->action(function (array $data): void {
                $this->removeGuests(
                    array_map('intval', (array) ($data['remove'] ?? [])),
                    trim((string) ($data['reason'] ?? '')),
                );
            });
    }

    /**
     * Take the people off, then say what happened to the money.
     *
     * Public so a test drives the same path the form does.
     *
     * @param  array<string, int>  $removeByCode
     */
    public function removeGuests(array $removeByCode, string $reason = ''): void
    {
        $booking = $this->booking();

        try {
            $result = app(RemoveGuestsFromBooking::class)($booking, $removeByCode, $reason !== '' ? $reason : null);
        } catch (ValidationException $exception) {
            Notification::make()
                ->danger()
                ->title(implode(' ', $exception->errors()['remove'] ?? []))
                ->send();

            return;
        }

        $booking->refresh();

        BookingCancelledByOperator::dispatch(
            $booking,
            'guests_removed',
            RefundMethod::Cash,
            0,
            0,
            $result['refund_cents'],
            $result['removed'],
            $reason !== '' ? $reason : null,
        );

        BookingGuestsRemoved::dispatch($booking->getKey(), $booking->tenant_id, $result['removed']);

        $body = match (true) {
            $result['refund_started_cents'] > 0 => __('bookings.remove_guests.done_refund', ['refund' => $this->euros($result['refund_started_cents'])]),
            $result['refund_cents'] > 0 => __('bookings.remove_guests.done_manual', ['refund' => $this->euros($result['refund_cents'])]),
            default => null,
        };

        $notification = Notification::make()
            ->success()
            ->title(trans_choice('bookings.remove_guests.done', $result['removed'], [
                'count' => $result['removed'],
                'total' => $this->euros($result['new_total_cents']),
            ]))
            ->body($body);

        // Money the operator has to hand back themselves stays on screen.
        if ($result['refund_cents'] > $result['refund_started_cents']) {
            $notification->persistent();
        }

        $notification->send();

        $this->record = $booking;
    }

    /** @param array<string, mixed> $remove */
    private function removalSentence(array $remove): string
    {
        $preview = RemoveGuestsFromBooking::preview(
            $this->booking(),
            array_map(static fn (mixed $value): int => (int) $value, $remove),
        );

        if ($preview['removed'] < 1) {
            return __('bookings.remove_guests.preview_none');
        }

        $sentence = trans_choice('bookings.remove_guests.preview', $preview['removed'], [
            'count' => $preview['removed'],
            'removed' => $this->euros($preview['removed_cents']),
            'total' => $this->euros($preview['new_total_cents']),
        ]);

        if ($preview['refund_cents'] > 0) {
            $sentence .= ' ' . __('bookings.remove_guests.preview_refund', ['refund' => $this->euros($preview['refund_cents'])]);
        } elseif ($preview['new_balance_cents'] > 0) {
            $sentence .= ' ' . __('bookings.remove_guests.preview_balance', ['balance' => $this->euros($preview['new_balance_cents'])]);
        }

        return $sentence;
    }

    /**
     * The band's name as the guest was shown it, frozen on the booking.
     *
     * @param  array<string, mixed>  $line
     */
    private function bandLabel(array $line): string
    {
        $label = $line['label'] ?? $line['code'] ?? '';

        if (is_array($label)) {
            return (string) ($label[app()->getLocale()] ?? reset($label) ?: ($line['code'] ?? ''));
        }

        return (string) $label;
    }

    /** Does this choice overrule the policy, and so need a reason (CXL-5)? */
    public static function isOverride(string $who, string $refund): bool
    {
        return match ($refund) {
            'policy' => false,
            'full' => $who !== 'operator',
            default => true,
        };
    }

    /**
     * Run the cancellation the operator chose, and say what it moved.
     *
     * Public so a test drives the same arithmetic the form does.
     */
    public function cancelWith(string $who, string $refund, int $percent, string $reason): void
    {
        $booking = $this->booking();
        $policy = RefundEntitlement::forCancellation($booking);
        $byOperator = $who === 'operator';

        $override = match (true) {
            $refund === 'voucher' => new RefundOverride(RefundMethod::Voucher, $reason, $percent),
            $refund === 'percent' => new RefundOverride(RefundMethod::Cash, $reason, $percent),
            $refund === 'full' && ! $byOperator => new RefundOverride(RefundMethod::Cash, $reason, 100),
            default => null,
        };

        $applied = match (true) {
            $override !== null => $override->percentAgainst($policy->percent),
            $refund === 'full' => 100,
            default => $policy->percent,
        };
        $moved = RefundEntitlement::atPercent($booking, $applied)->totalCents;

        $cancelled = app(CancelBooking::class)(
            booking: $booking,
            reason: $byOperator ? CancelReason::Operator : CancelReason::GuestRequest,
            by: CancelledBy::Operator,
            override: $override,
            refundInFull: $refund === 'full' && $byOperator,
        );

        BookingCancelledByOperator::dispatch(
            $cancelled,
            'booking',
            $override !== null ? $override->method : RefundMethod::Cash,
            $policy->percent,
            $applied,
            $moved,
            (int) $cancelled->pax_total,
            $reason !== '' ? $reason : null,
        );

        Notification::make()
            ->success()
            ->title($moved > 0
                ? __('bookings.cancel.done', ['amount' => $this->euros($moved)])
                : __('bookings.cancel.done_nothing'))
            ->send();

        $this->record = $cancelled;
    }

    private function euros(int $cents): string
    {
        return MoneyFormatter::format($cents, app()->getLocale(), MoneyFormatter::currency());
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
