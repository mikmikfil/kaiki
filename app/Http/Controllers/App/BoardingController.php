<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domain\Booking\Actions\CheckInGuest;
use App\Enums\BookingStatus;
use App\Exceptions\CheckInRefused;
use App\Filament\App\Pages\CheckIn;
use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Boarding on a quay with no signal (spec OPS-12, BKG-20, OOS-6).
 *
 * > **OPS-12** *"The check-in page tolerates an intermittent connection: scans
 * > queue locally and sync when connectivity returns, and scanning an
 * > already-checked-in ticket reports that clearly instead of failing."*
 *
 * ## Why this is not the Filament page
 *
 * {@see CheckIn} is Livewire. Its scan box is
 * `wire:model.live.debounce.300ms` and every action is a round trip, so with no
 * signal the page does not even echo the code somebody just scanned. That is
 * not a bug in it — Livewire is a server-rendered model and this requirement is
 * the one place in the product where the server may be unreachable.
 *
 * OOS-6 forbids a native app, so "works without a signal" has to mean a web
 * page that has already been loaded. This is that page: plain Blade, its own
 * JavaScript, a service worker scoped to its own path, and an IndexedDB queue.
 * The Filament page stays for everything else — the searchable list, the
 * override with a reason, the no-show marking — and both write through the same
 * Action.
 *
 * ## The server decides, not the phone
 *
 * A queued scan is a **claim** that somebody boarded, and it is reconciled when
 * it reaches {@see CheckInGuest}. The phone shows an optimistic tick from its
 * cached manifest; the server's answer replaces it. Two crew scanning the same
 * guest on two phones is the case that matters, and `CheckInGuest` already
 * handles it: both writes are conditional updates and the second returns false.
 *
 * That is why this endpoint needed no new domain logic. The Action was already
 * idempotent and safe to replay — which is exactly what a sync-on-reconnect
 * queue needs, and the reason OPS-12 turned out to be a client-side issue.
 *
 * ## The manifest is embedded, not fetched
 *
 * Everything the page needs to recognise a ticket offline is in the HTML that
 * loaded it. A page that fetched its manifest on load would be a page that
 * works only when it does not need to.
 */
class BoardingController
{
    /**
     * How far either side of now the boarding list reaches.
     *
     * The same twelve hours back and twenty-four forward the Filament page
     * uses. Crew board the morning boat at seven for a nine o'clock departure,
     * and the evening cruise is still being reconciled at midnight.
     */
    private const HOURS_BACK = 12;

    private const HOURS_FORWARD = 24;

    /** The page, with today's manifest baked into it. */
    public function show(Request $request): View
    {
        abort_unless(self::permitted(), 403);

        return view('app.boarding', [
            'manifest' => $this->manifest(),
            'generatedAt' => Carbon::now()->toIso8601String(),
            'tenantId' => Tenancy::id(),
        ]);
    }

    /**
     * A batch of scans, each answered on its own.
     *
     * A batch rather than one request per scan: a phone that regains signal
     * after twenty minutes on a boat has a queue, and twenty requests racing
     * each other would be twenty chances to hit a rate limit at the moment the
     * crew most need the sync to work.
     *
     * Each scan is answered separately and **the whole batch never fails**. One
     * unknown ticket among nineteen good ones must not roll back the nineteen.
     */
    public function scan(Request $request): JsonResponse
    {
        abort_unless(self::permitted(), 403);

        /** @var User $actor */
        $actor = Auth::user();

        $scans = $request->input('scans');

        if (! is_array($scans)) {
            return response()->json(['results' => []], 422);
        }

        $results = [];

        foreach (array_slice($scans, 0, 200) as $scan) {
            $code = is_array($scan) ? (string) ($scan['ticket_code'] ?? '') : '';

            if ($code === '') {
                continue;
            }

            $results[] = $this->one($code, $actor);
        }

        return response()->json(['results' => $results]);
    }

    /**
     * One ticket, and the three answers a quayside needs to tell apart.
     *
     * `checked_in` — done, by this scan.
     * `already` — OPS-12's second clause. Somebody is already aboard, and
     *   saying so plainly is different from failing.
     * `refused` — the window is closed, or the booking is not confirmed. The
     *   reason is the Action's own Greek sentence (CNV-11), not a code.
     * `unknown` — the ticket is not ours.
     *
     * @return array<string, mixed>
     */
    private function one(string $code, User $actor): array
    {
        $guest = BookingGuest::query()->where('ticket_code', $code)->first();

        if (! $guest instanceof BookingGuest) {
            return ['ticket_code' => $code, 'status' => 'unknown'];
        }

        try {
            $done = app(CheckInGuest::class)($guest, $actor);
        } catch (CheckInRefused $refused) {
            return [
                'ticket_code' => $code,
                'status' => 'refused',
                'guest' => $guest->full_name,
                'message' => $refused->getMessage(),
            ];
        }

        return [
            'ticket_code' => $code,
            // `false` from the Action means another scan won — the row was
            // already checked in. That is OPS-12's "reports that clearly".
            'status' => $done ? 'checked_in' : 'already',
            'guest' => $guest->full_name,
            'checked_in_at' => $guest->fresh()?->checked_in_at?->toIso8601String(),
        ];
    }

    /**
     * Everyone the crew might board today, by ticket code.
     *
     * A name and a seat and nothing else. TEN-8 gives crew `ViewPaxList` and
     * `CheckInGuests` and explicitly not pricing, financials or documents — and
     * this payload sits in a phone's cache on a boat, which is the worst place
     * in the product for a document number to be. There is no case for one
     * here, so there is no mechanism that could include one.
     *
     * @return array<int, array<string, mixed>>
     */
    private function manifest(): array
    {
        $now = Carbon::now();

        $bookings = Booking::query()
            ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::CheckedIn])
            ->whereBetween('starts_at_utc', [
                $now->copy()->subHours(self::HOURS_BACK),
                $now->copy()->addHours(self::HOURS_FORWARD),
            ])
            ->with(['product', 'guests'])
            ->get();

        $rows = [];

        foreach ($bookings as $booking) {
            foreach ($booking->guests as $guest) {
                if ($guest->ticket_code === null) {
                    continue;
                }

                $rows[] = [
                    'ticket_code' => $guest->ticket_code,
                    'name' => $guest->full_name ?? __('boarding.unnamed'),
                    'reference' => $booking->reference,
                    'trip' => (string) ($booking->product->title ?? ''),
                    'local_time' => substr((string) $booking->local_time, 0, 5),
                    'local_date' => $booking->local_date instanceof Carbon
                        ? $booking->local_date->toDateString()
                        : (string) $booking->local_date,
                    'checked_in' => $guest->checked_in_at !== null,
                ];
            }
        }

        return $rows;
    }

    /** TEN-8's crew capability, asserted explicitly — a route has no policy. */
    private static function permitted(): bool
    {
        return Tenancy::check()
            && (Auth::user()?->hasCapability(Capability::CheckInGuests) ?? false);
    }
}
