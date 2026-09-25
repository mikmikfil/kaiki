<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets;

use App\Domain\Availability\Actions\SailBelowMinimum;
use App\Domain\Booking\Actions\CancelDeparture;
use App\Domain\Booking\Actions\ConfirmManualRefund;
use App\Domain\Booking\Actions\RecordManualPayment;
use App\Domain\Operations\Support\AttentionItem;
use App\Domain\Operations\Support\AttentionItems;
use App\Domain\Operations\Support\FirstSteps;
use App\Enums\BookingStatus;
use App\Enums\DepartureCancelReason;
use App\Enums\DepartureStatus;
use App\Enums\PaymentGatewayName;
use App\Filament\App\Pages\CalendarSync;
use App\Filament\App\Resources\BookingResource;
use App\Filament\App\Resources\DepartureResource;
use App\Filament\App\Resources\QuoteResource;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\IcalSource;
use App\Models\Payment;
use App\Models\Quote;
use App\Support\Authorization\Capability;
use App\Support\Format\MoneyFormatter;
use App\Support\Tenancy;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

/**
 * «Χρειάζονται προσοχή» — the decisions waiting on a person (spec OPS-1).
 *
 * ## Why this is not the failure feed
 *
 * OPS-21's feed collects things the *system* could not do — a refused payment,
 * a myDATA submission that bounced — and every row there ends in a retry. This
 * panel collects things the system did correctly and cannot finish alone: a
 * boat short of its minimum, a passport that has not arrived, money past its
 * date. Every row is a question for the operator.
 *
 * ## Every row leads somewhere (product owner, 2026-09-17)
 *
 * «Τι νόημα έχει αν δεν μπορείς να κάνεις τίποτα από εκεί;» The rows used to
 * say what was wrong and stop. Now each one is a box that opens the exact
 * screen where it is dealt with, and says in its button what that is:
 *
 * | Item                         | Opens                                  |
 * |------------------------------|----------------------------------------|
 * | Departure under its minimum  | that departure (cancel, move, manifest) |
 * | Missing passenger details    | that booking, and a call button         |
 * | Balance past its due date    | that booking (record payment), and call |
 * | Quote about to lapse         | that quote                              |
 * | Cash/transfer to hand back   | that booking, «Επιστράφηκε», and call   |
 * | Calendar feed failing        | «Συγχρονισμός ημερολογίων»              |
 *
 * A row whose screen this person may not open is left out: a decision they
 * cannot act on is exactly the complaint. Five show at first, soonest deadline
 * first, and «Όλα (N)» opens the rest in place.
 *
 * ## The two real decisions are taken here (product owner, 2026-09-17)
 *
 * «Ουσιαστικά δεν παίρνω καμία απόφαση, απλά βλέπω.» So the two rows that are
 * a yes-or-no answer get their answers as buttons, each confirmed first:
 *
 * - **Under its minimum:** «Ακύρωση αναχώρησης» runs {@see CancelDeparture}
 *   with reason `min_pax` — the same path the departures screen uses, refunds
 *   and all — and «Φεύγει κανονικά» runs {@see SailBelowMinimum}.
 * - **Balance past due:** «Πληρώθηκε» records cash or a transfer through
 *   {@see RecordManualPayment}, the booking page's own action, in a small form.
 *
 * Both need `ManageBookings`, like the screens they shortcut, and are not drawn
 * for anybody without it. The row goes as soon as the answer is saved.
 *
 * ## It disappears when it is empty
 *
 * A permanently visible panel saying "nothing needs attention" trains somebody
 * to stop looking at that part of the screen, and then it is invisible on the
 * morning it finally has something in it.
 */
class NeedsAttention extends Widget implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    protected static string $view = 'filament.app.widgets.needs-attention';

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    /** How many rows show before «Όλα (N)». */
    public const FIRST = 5;

    public bool $showAll = false;

    /**
     * Rows answered from here, kept for a few seconds where they stood.
     *
     * Without it the next item slides into the answered row's place the moment
     * the answer is saved, and the list looks as if it ignored the click (Mike,
     * 2026-09-23: «κάνω ενέργειες αλλά παραμένουν χωρίς να γίνεται τίποτα»).
     * The view fades each one out and calls {@see self::forgetAnswer()}.
     *
     * @var array<string, array{title: string, outcome: string, position: int}>
     */
    public array $answered = [];

    /**
     * Refreshed on the same cadence as the rest of the dashboard: nothing on
     * this list changes in seconds.
     */
    protected static ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return ! FirstSteps::applies() && self::items() !== [];
    }

    /**
     * Filament asks {@see canView()} again on every request, and "has items" is
     * a question about the data, not about who is asking. Answering the last
     * row — or the list emptying between two polls — made the next request a
     * 403, which Livewire shows as a black error box over the dashboard
     * (2026-09-23). So an open widget is refused only for the reason a closed
     * one would be: the operator is still on their first steps.
     */
    public function hydrateCanAuthorizeAccess(): void
    {
        abort_if(FirstSteps::applies(), 403);
    }

    /**
     * @return list<AttentionItem>
     */
    public function getItems(): array
    {
        return self::items();
    }

    /**
     * The rows this person can act on, each with where it leads.
     *
     * @return list<array{item: AttentionItem, url: string, action: string, phone: string|null, decide: string|null, id: int|null}>
     */
    public function getRows(): array
    {
        $rows = [];

        foreach (self::items(everything: true) as $item) {
            $row = self::actionFor($item);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    public function toggleAll(): void
    {
        $this->showAll = ! $this->showAll;
    }

    public function forgetAnswer(string $key): void
    {
        unset($this->answered[$key]);
    }

    /**
     * The rows to draw: the live ones, with the just-answered ones put back
     * where they were.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function withAnswered(array $rows): array
    {
        $live = array_map(static fn (array $row): string => $row['item']->key, $rows);

        // Still on the list — a balance paid only in part — so the live row
        // speaks for itself and a «Πληρώθηκε» beside it would contradict it.
        $answered = array_diff_key($this->answered, array_flip($live));
        uasort($answered, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        foreach ($answered as $key => $answer) {
            array_splice($rows, min($answer['position'], count($rows)), 0, [[
                'done' => true,
                'key' => $key,
                'title' => $answer['title'],
                'outcome' => $answer['outcome'],
            ]]);
        }

        return $rows;
    }

    /** Note the row before its answer takes it off the list. */
    private function answering(string $key, string $outcome): void
    {
        foreach ($this->getRows() as $position => $row) {
            if ($row['item']->key === $key) {
                $this->answered[$key] = [
                    'title' => $row['item']->title,
                    'outcome' => __('attention.done.' . $outcome),
                    'position' => $position,
                ];

                // The count in the box at the top of the page.
                $this->dispatch('attention-answered');

                return;
            }
        }
    }

    /** May this person answer the decisions from here? */
    public static function canDecide(): bool
    {
        return Auth::user()?->hasCapability(Capability::ManageBookings) ?? false;
    }

    /** «Ακύρωση αναχώρησης», for a sailing short of its minimum. */
    public function cancelDepartureAction(): Action
    {
        return Action::make('cancelDeparture')
            ->label(__('attention.decide.cancel'))
            ->icon('heroicon-m-x-circle')
            ->color('danger')
            ->size('lg')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => __('attention.decide.cancel_heading', [
                'trip' => (string) $this->departure($arguments)?->product?->title,
            ]))
            ->modalDescription(fn (array $arguments): string => trans_choice(
                'attention.decide.cancel_body',
                $this->liveBookings($arguments),
                ['count' => $this->liveBookings($arguments)],
            ))
            ->modalSubmitActionLabel(__('attention.decide.cancel_confirm'))
            ->visible(static fn (): bool => self::canDecide())
            ->action(function (array $arguments): void {
                $departure = $this->departure($arguments);

                if (! $departure instanceof Departure || ! self::canDecide()) {
                    return;
                }

                $this->answering('departure:' . $departure->getKey(), 'cancelled');

                $cancelled = app(CancelDeparture::class)(
                    departure: $departure,
                    reason: DepartureCancelReason::MinPax,
                    byUserId: Auth::id(),
                );

                Notification::make()
                    ->success()
                    ->title(trans_choice('attention.decide.cancelled', $cancelled, ['count' => $cancelled]))
                    ->send();
            });
    }

    /** «Φεύγει κανονικά»: the sailing goes ahead short, and the question is closed. */
    public function sailAnywayAction(): Action
    {
        return Action::make('sailAnyway')
            ->label(__('attention.decide.sail'))
            ->icon('heroicon-m-check-circle')
            ->color('gray')
            ->size('lg')
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-check-circle')
            ->modalIconColor('primary')
            ->modalHeading(__('attention.decide.sail_heading'))
            ->modalDescription(fn (array $arguments): string => __('attention.decide.sail_body', [
                'sold' => (int) $this->departure($arguments)?->seats_sold,
                'minimum' => (int) $this->departure($arguments)?->min_pax,
            ]))
            ->modalSubmitActionLabel(__('attention.decide.sail_confirm'))
            ->visible(static fn (): bool => self::canDecide())
            ->action(function (array $arguments): void {
                $departure = $this->departure($arguments);

                if (! $departure instanceof Departure || ! self::canDecide()) {
                    return;
                }

                $this->answering('departure:' . $departure->getKey(), 'sailed');

                app(SailBelowMinimum::class)($departure);

                Notification::make()->success()->title(__('attention.decide.sailed'))->send();
            });
    }

    /** «Πληρώθηκε», for a balance past its date: the booking page's own form. */
    public function markPaidAction(): Action
    {
        return Action::make('markPaid')
            ->label(__('attention.decide.paid'))
            ->icon('heroicon-m-banknotes')
            ->color('primary')
            ->size('lg')
            ->modalHeading(fn (array $arguments): string => __('attention.decide.paid_heading', [
                'reference' => (string) $this->booking($arguments)?->reference,
            ]))
            ->modalWidth('md')
            ->visible(static fn (): bool => self::canDecide())
            ->form(fn (array $arguments): array => [
                TextInput::make('amount')
                    ->label(__('bookings.payment.amount'))
                    ->numeric()
                    ->step('0.01')
                    ->minValue(0.01)
                    ->required()
                    ->default(((int) $this->booking($arguments)?->balance_cents) / 100)
                    ->helperText(__('bookings.payment.owed', [
                        'amount' => MoneyFormatter::format(
                            (int) $this->booking($arguments)?->balance_cents,
                            app()->getLocale(),
                            MoneyFormatter::currency(),
                        ),
                    ])),
                Select::make('gateway')
                    ->label(__('bookings.payment.how'))
                    ->options([
                        PaymentGatewayName::Cash->value => PaymentGatewayName::Cash->label(),
                        PaymentGatewayName::Pos->value => PaymentGatewayName::Pos->label(),
                        PaymentGatewayName::BankTransfer->value => PaymentGatewayName::BankTransfer->label(),
                    ])
                    ->default(PaymentGatewayName::Cash->value)
                    ->required(),
                TextInput::make('reference')
                    ->label(__('bookings.payment.reference'))
                    ->maxLength(100),
            ])
            ->modalSubmitActionLabel(__('attention.decide.paid_confirm'))
            ->action(function (array $arguments, array $data): void {
                $booking = $this->booking($arguments);

                if (! $booking instanceof Booking || ! self::canDecide()) {
                    return;
                }

                $this->answering('balance:' . $booking->getKey(), 'paid');

                app(RecordManualPayment::class)(
                    booking: $booking,
                    // `round`, not a cast: `(int) (1.15 * 100)` is 114.
                    amountCents: (int) round(((float) $data['amount']) * 100),
                    gateway: PaymentGatewayName::from((string) $data['gateway']),
                    reference: ($data['reference'] ?? '') !== '' ? (string) $data['reference'] : null,
                    userId: Auth::id(),
                );

                Notification::make()->success()->title(__('bookings.payment.recorded'))->send();
            });
    }

    /**
     * «Επιστράφηκε», for cash or a transfer handed back by hand (2026-09-23).
     *
     * Confirmed first, and worded so it is pressed after the money has gone
     * back rather than as a promise to send it.
     */
    public function markRefundedAction(): Action
    {
        return Action::make('markRefunded')
            ->label(__('attention.decide.refunded'))
            ->icon('heroicon-m-banknotes')
            ->color('primary')
            ->size('lg')
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-banknotes')
            ->modalIconColor('primary')
            ->modalHeading(fn (array $arguments): string => __('attention.decide.refunded_heading', [
                'amount' => MoneyFormatter::format(
                    (int) $this->refund($arguments)?->amount_cents,
                    app()->getLocale(),
                    MoneyFormatter::currency(),
                ),
                'guest' => (string) $this->refund($arguments)?->booking?->guest_name,
            ]))
            ->modalDescription(fn (array $arguments): string => __('attention.decide.refunded_body', [
                'method' => __('attention.refund.' . ($this->refund($arguments)?->gateway->value ?? PaymentGatewayName::Cash->value)),
            ]))
            ->modalSubmitActionLabel(__('attention.decide.refunded_confirm'))
            ->visible(static fn (): bool => self::canDecide())
            ->action(function (array $arguments): void {
                $refund = $this->refund($arguments);

                if (! $refund instanceof Payment || ! self::canDecide()) {
                    return;
                }

                $this->answering('refund:' . $refund->getKey(), 'refunded');

                app(ConfirmManualRefund::class)($refund);

                Notification::make()->success()->title(__('attention.decide.refunded_done'))->send();
            });
    }

    /** @param array<string, mixed> $arguments */
    private function refund(array $arguments): ?Payment
    {
        $id = $arguments['refund'] ?? null;

        return is_numeric($id) ? Payment::query()->with('booking')->find((int) $id) : null;
    }

    /** @param array<string, mixed> $arguments */
    private function departure(array $arguments): ?Departure
    {
        $id = $arguments['departure'] ?? null;

        return is_numeric($id) ? Departure::query()->with('product')->find((int) $id) : null;
    }

    /** @param array<string, mixed> $arguments */
    private function booking(array $arguments): ?Booking
    {
        $id = $arguments['booking'] ?? null;

        return is_numeric($id) ? Booking::query()->find((int) $id) : null;
    }

    /** @param array<string, mixed> $arguments */
    private function liveBookings(array $arguments): int
    {
        $departure = $this->departure($arguments);

        if (! $departure instanceof Departure) {
            return 0;
        }

        return Booking::query()
            ->where('departure_id', $departure->getKey())
            ->whereIn('status', array_map(
                static fn (BookingStatus $status): string => $status->value,
                array_filter(BookingStatus::cases(), static fn (BookingStatus $status): bool => $status->isLive()),
            ))
            ->count();
    }

    /**
     * Where one item is fixed, or null when this person cannot go there.
     *
     * @return array{item: AttentionItem, url: string, action: string, phone: string|null, decide: string|null, id: int|null}|null
     */
    public static function actionFor(AttentionItem $item): ?array
    {
        $subject = $item->subject;
        $type = strstr($item->key, ':', true) ?: $item->key;

        return match (true) {
            $subject instanceof Departure && DepartureResource::canViewAny() => [
                'item' => $item,
                'url' => DepartureResource::getUrl('edit', ['record' => $subject]),
                'action' => $type === 'captain' ? __('attention.actions.captain') : __('attention.actions.departure'),
                'phone' => null,
                // «Φεύγει κανονικά / Ακύρωση» answers a boat short of its
                // minimum, not one with nobody to skipper her.
                'decide' => $type === 'departure' && $subject->status === DepartureStatus::Scheduled && self::canDecide() ? 'departure' : null,
                'id' => (int) $subject->getKey(),
            ],
            $subject instanceof Booking && BookingResource::canViewAny() => [
                'item' => $item,
                'url' => BookingResource::getUrl('view', ['record' => $subject]),
                'action' => __($type === 'balance' ? 'attention.actions.balance' : 'attention.actions.details'),
                'phone' => is_string($subject->guest_phone) && $subject->guest_phone !== '' ? $subject->guest_phone : null,
                'decide' => $type === 'balance' && $subject->balance_cents > 0 && self::canDecide() ? 'balance' : null,
                'id' => (int) $subject->getKey(),
            ],
            $subject instanceof Payment && $subject->booking instanceof Booking && BookingResource::canViewAny() => [
                'item' => $item,
                'url' => BookingResource::getUrl('view', ['record' => $subject->booking]),
                'action' => __('attention.actions.refund'),
                'phone' => is_string($subject->booking->guest_phone) && $subject->booking->guest_phone !== '' ? $subject->booking->guest_phone : null,
                'decide' => ConfirmManualRefund::isOwed($subject) && self::canDecide() ? 'refund' : null,
                'id' => (int) $subject->getKey(),
            ],
            $subject instanceof Quote && QuoteResource::canViewAny() => [
                'item' => $item,
                'url' => QuoteResource::getUrl('edit', ['record' => $subject]),
                'action' => __('attention.actions.quote'),
                'phone' => null,
                'decide' => null,
                'id' => null,
            ],
            $subject instanceof IcalSource && CalendarSync::canAccess() => [
                'item' => $item,
                'url' => CalendarSync::getUrl(),
                'action' => __('attention.actions.calendar'),
                'phone' => null,
                'decide' => null,
                'id' => null,
            ],
            default => null,
        };
    }

    /**
     * @return list<AttentionItem>
     */
    private static function items(bool $everything = false): array
    {
        $tenant = Tenancy::current();

        if ($tenant === null) {
            return [];
        }

        $items = new AttentionItems($tenant->timezone);

        return $everything ? $items->everything() : $items->all();
    }
}
