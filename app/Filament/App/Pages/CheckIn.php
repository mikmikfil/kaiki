<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Domain\Booking\Actions\CheckInGuest;
use App\Domain\Booking\Actions\MarkNoShow;
use App\Domain\Booking\Data\CheckInOverride;
use App\Domain\Booking\Support\CheckInWindow;
use App\Enums\BookingStatus;
use App\Exceptions\CheckInRefused;
use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Format\DateTimeFormatter;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * The one Filament surface with a real phone requirement (spec BKG-20).
 *
 * > **BKG-20 (FIXED)** On the day of departure, crew check in guests through a
 * > QR scan page in Filament that **works on a phone**.
 *
 * ## What "works on a phone" actually constrains
 *
 * Everywhere else in `/app` a small screen is a courtesy. Here it is the only
 * screen: the user is standing on a pier in the sun, holding a phone in one
 * hand, with somebody waiting in front of them. Three things follow, and they
 * are the reason this is a Page rather than a Resource:
 *
 * - **The scan is the primary control**, first on the page, focused on load and
 *   submitted by the scanner's own carriage return. A resource's table with a
 *   search box buried above it inverts that.
 * - **One tap per guest.** Check-in is a row action with no confirmation step,
 *   because the confirmation is the person standing there. Undo is beside it.
 * - **A ticket can arrive as a URL.** A phone camera scanning the QR opens
 *   `?ticket=…` directly, so the code is bound to the query string and acted on
 *   without a form submission ({@see TicketQr}).
 *
 * ## Crew see this page and almost nothing else
 *
 * TEN-8 gives crew `ViewPaxList` and `CheckInGuests` — *"no pricing, no
 * financials, no guest documents beyond what the manifest shows"*. A Page has
 * no model to hang a policy on and Filament allows what nothing forbids, so
 * access is asserted explicitly against the capability, and the page renders a
 * **name and a seat** and nothing else. There is no price on it, no payment
 * status and no document number — not hidden behind a condition, absent.
 */
class CheckIn extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-qr-code';

    /** First in its group. On the day it matters it is the only page that matters. */
    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.app.pages.check-in';

    /**
     * The scanned code, straight off the query string.
     *
     * A phone camera opening the QR's URL lands here with the ticket already
     * resolved and no form to submit — which is the difference between one
     * gesture and four on a moving boat.
     */
    #[Url(as: 'ticket', keep: false)]
    public string $ticket = '';

    public function getTitle(): string|Htmlable
    {
        return __('checkin.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('checkin.nav');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.operations');
    }

    /**
     * TEN-8's two crew capabilities, and nothing wider.
     *
     * `CheckInGuests` rather than `ViewPaxList`: somebody who may only *read*
     * the manifest has no business on a page whose every control is a write.
     */
    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->hasCapability(Capability::CheckInGuests);
    }

    /**
     * The guest behind the scanned code, if any.
     *
     * Resolved on every render rather than held in component state: a crew
     * member scans one ticket after another, and stale state on a page like
     * this checks in the wrong person.
     */
    public function scannedGuest(): ?BookingGuest
    {
        $code = trim($this->ticket);

        if ($code === '') {
            return null;
        }

        $guest = BookingGuest::query()
            ->with(['booking.product'])
            ->where('ticket_code', $code)
            ->first();

        // Found without tenancy would be a cross-tenant read; the scoped query
        // above is the tenant-safe half of `findByTicketCode()`, which exists
        // for the unauthenticated case this page is not.
        return $guest;
    }

    /**
     * Today's sailings, with how many are aboard (BKG-20's second half).
     *
     * A twelve-hour look-back and a day ahead, rather than a calendar day. A
     * sunset cruise that returns after midnight is still tonight's boat to the
     * crew member aboard it, and "today" computed from a date would drop it off
     * the page at exactly the moment they need to mark the last no-show.
     *
     * @return Collection<int, Booking>
     */
    public function todaysBookings(): Collection
    {
        $now = Carbon::now();

        return Booking::query()
            ->with(['guests', 'product'])
            ->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::CheckedIn->value])
            ->whereBetween('starts_at_utc', [$now->copy()->subHours(12), $now->copy()->addHours(24)])
            ->orderBy('starts_at_utc')
            ->get();
    }

    /** The window, as a crew member reads it. */
    public function windowFor(Booking $booking): string
    {
        return DateTimeFormatter::time(CheckInWindow::forBooking($booking, $booking->product)->opensAt);
    }

    /** One tap. The confirmation is the person standing there. */
    public function checkInAction(): Action
    {
        return Action::make('checkIn')
            ->label(__('checkin.actions.check_in'))
            ->icon('heroicon-o-check')
            ->action(function (array $arguments): void {
                $guest = $this->guestFromArguments($arguments);

                if (! $guest instanceof BookingGuest) {
                    return;
                }

                $this->attempt($guest, null);
            });
    }

    /**
     * BKG-22's early check-in, which exists to collect a reason.
     *
     * The modal is the requirement. {@see CheckInOverride} refuses to be
     * constructed without a reason, so a form field that merely *asks* would
     * still be enforced — but the operator needs to be told the reason is
     * recorded, and that sentence is why the modal has a description.
     */
    public function overrideAction(): Action
    {
        return Action::make('checkInEarly')
            ->label(__('checkin.actions.override.label'))
            ->icon('heroicon-o-clock')
            ->color('warning')
            ->modalHeading(__('checkin.actions.override.heading'))
            ->modalSubmitActionLabel(__('checkin.actions.override.confirm'))
            ->form([
                Textarea::make('reason')
                    ->label(__('checkin.actions.override.reason'))
                    ->placeholder(__('checkin.actions.override.reason_placeholder'))
                    ->required()
                    ->rows(2),
            ])
            ->action(function (array $arguments, array $data): void {
                $guest = $this->guestFromArguments($arguments);

                if (! $guest instanceof BookingGuest) {
                    return;
                }

                $this->attempt($guest, new CheckInOverride((string) $data['reason']));
            });
    }

    /** BKG-23, per guest, reversible, and nothing else follows from it. */
    public function noShowAction(): Action
    {
        return Action::make('markNoShow')
            ->label(__('checkin.actions.mark_no_show'))
            ->icon('heroicon-o-x-mark')
            ->color('danger')
            ->action(function (array $arguments): void {
                $guest = $this->guestFromArguments($arguments);

                if (! $guest instanceof BookingGuest) {
                    return;
                }

                $flag = ! $guest->no_show;

                app(MarkNoShow::class)->forGuest($guest, $flag);

                Notification::make()
                    ->success()
                    ->title(__($flag ? 'checkin.done.no_show' : 'checkin.done.no_show_cleared', [
                        'name' => $guest->full_name ?? __('checkin.guest.unnamed'),
                    ]))
                    ->send();
            });
    }

    /**
     * Run the check-in and say what happened, in the crew member's language.
     *
     * CNV-11: {@see CheckInRefused} carries a lang-file sentence and this shows
     * it. The alternative — letting the exception surface — puts an English
     * developer's message on a Greek deckhand's phone.
     */
    private function attempt(BookingGuest $guest, ?CheckInOverride $override): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return;
        }

        $name = $guest->full_name ?? __('checkin.guest.unnamed');

        try {
            $checkedIn = app(CheckInGuest::class)($guest, $user, $override);
        } catch (CheckInRefused $refused) {
            Notification::make()
                ->danger()
                ->title($refused->getMessage())
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(__($checkedIn ? 'checkin.done.checked_in' : 'checkin.done.already', ['name' => $name]))
            ->send();
    }

    /** @param array<string, mixed> $arguments */
    private function guestFromArguments(array $arguments): ?BookingGuest
    {
        $id = $arguments['guest'] ?? null;

        if (! is_int($id) && ! is_string($id)) {
            return null;
        }

        return BookingGuest::query()->with('booking.product')->find($id);
    }
}
