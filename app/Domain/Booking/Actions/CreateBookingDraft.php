<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Availability\Actions\HoldSeats;
use App\Domain\Availability\Support\CountedSeats;
use App\Domain\Booking\Data\BookingDraftData;
use App\Domain\Booking\Support\LeadGuest;
use App\Domain\Pricing\Actions\ComputePrice;
use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Enums\GuestDetailsStatus;
use App\Exceptions\HoldRefused;
use App\Models\AgeBand;
use App\Models\Booking;
use App\Models\Departure;
use App\Support\Booking\BookingReference;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * `POST /bookings`, minus the HTTP (spec BKG-6, BKG-7, CXL-2, AVL-41).
 *
 * BKG-6 lists seven things and this Action does all seven in one transaction:
 * validate the party, compute the price server-side, write `price_snapshot` and
 * the policy snapshot, acquire the hold, set `hold_expires_at`, generate the
 * reference, mint the tokens.
 *
 * ## The price is computed, never accepted
 *
 * PRC-1, and the reason this is an Action rather than a controller assembling
 * numbers: the same {@see ComputePrice} answers the public quote endpoint, the
 * operator's manual booking and this, so a guest cannot be shown one figure and
 * charged another.
 *
 * ## The policy snapshot is taken here and never again
 *
 * CXL-2, marked RESOLVED: *"written when the Booking row is first persisted
 * with a resolved price … and is immutable thereafter."* `CLAUDE.md` names the
 * failure it prevents — a refund computed from the *current* policy rather than
 * the one the guest agreed to, which is the operator quietly changing the terms
 * of a sale after the fact.
 *
 * ## The reference is a race, and the unique index is what settles it
 *
 * §2.5 says so outright: *"do not rely on `SELECT … WHERE reference = ?` first
 * — that check-then-insert is exactly the race the unique index exists to
 * close, and the check is a no-op under SQLite's lack of locking."* So the
 * insert is attempted with a random reference and the **unique violation** is
 * caught. Five attempts, then the random part widens from five characters to
 * six (ADR-0007 item 4), which is 729 million per tenant and settles it.
 *
 * ## A quote-mode draft holds nothing
 *
 * AVL-41. It is also not a `draft`: §4.1 starts a quote-mode booking in
 * `quote_requested`, which has no hold and no `hold_expires_at`, so the sweeper
 * never sees it and the availability read never counts it.
 */
final class CreateBookingDraft
{
    public function __construct(
        private readonly ComputePrice $computePrice,
        private readonly HoldSeats $holdSeats,
    ) {}

    /**
     * @throws ValidationException when the party cannot be priced
     * @throws HoldRefused when the seats went while the guest was deciding
     */
    public function __invoke(BookingDraftData $data): Booking
    {
        $product = $data->product;
        $bands = $product->ageBands;
        $pax = CountedSeats::sanitise($bands, $data->paxByCode);

        $quote = ($this->computePrice)(
            product: $product,
            date: $data->date,
            paxByCode: $pax,
            extraQuantities: $data->extraQuantities,
            extraHours: $data->extraHours,
        );

        $departure = $product->mode === BookingMode::PerSeat
            ? $this->resolveDeparture($product->getKey(), $data)
            : null;

        $isQuoteMode = $product->mode === BookingMode::Quote;

        $booking = $this->insertWithReference(function (string $reference) use ($data, $product, $bands, $pax, $quote, $departure, $isQuoteMode): Booking {
            $window = $this->window($data, $departure);

            $booking = new Booking;

            $booking->forceFill([
                'uuid' => (string) Str::uuid(),
                'reference' => $reference,
                'product_id' => $product->getKey(),
                'vessel_id' => $departure === null ? $product->vessel_id : $departure->vessel_id,
                'departure_id' => $departure?->getKey(),
                'mode' => $product->mode,
                // A quote-mode booking is not a draft and never holds anything.
                'status' => $isQuoteMode ? BookingStatus::QuoteRequested : BookingStatus::Draft,
                'source' => $data->source,
                'locale' => $data->locale,

                'local_date' => $window['local_date'],
                'local_time' => $window['local_time'],
                'starts_at_utc' => $window['starts_at_utc'],
                'ends_at_utc' => $window['ends_at_utc'],

                'guest_name' => trim($data->guestName),
                'guest_email' => mb_strtolower(trim($data->guestEmail)),
                // BKG-8: null rather than a refusal. A number we cannot read
                // costs an SMS; refusing costs the booking.
                'guest_phone' => LeadGuest::normalisePhone($data->guestPhone, $data->guestCountry),
                'guest_country' => $data->guestCountry,

                'pax_total' => CountedSeats::totalPersons($pax),
                'pax_capacity_total' => CountedSeats::counted($bands, $pax),
                'pax_breakdown' => $this->paxBreakdown($bands, $pax, $quote->snapshot->toArray()['lines'] ?? []),
                'extras_snapshot' => $this->extrasSnapshot($quote->snapshot->toArray()),

                // CXL-2. Both snapshots, at first persistence, immutable after.
                'policy_snapshot' => $product->cancellationPolicy?->toSnapshotData()->toArray(),
                'price_snapshot' => $quote->snapshot->toArray(),

                'subtotal_cents' => $quote->snapshot->subtotalCents,
                'extras_cents' => $quote->snapshot->extrasCents,
                'discount_cents' => $quote->snapshot->discountCents,
                'total_cents' => $quote->totalCents,
                'deposit_cents' => $quote->depositCents,
                'balance_cents' => $quote->totalCents,

                'vat_rate_bp' => (int) ($quote->snapshot->vat['rate_bp'] ?? 0),
                'vat_category' => (string) ($quote->snapshot->vat['vat_category'] ?? ''),
                'vat_cents' => (int) ($quote->snapshot->vat['vat_cents'] ?? 0),

                'guest_details_status' => GuestDetailsStatus::NotRequired,
                // Minted now because `/b/{token}` is the guest's only way back
                // to a booking they have not paid for yet. `guest_details_token`
                // is deliberately **not** minted here — §2.5 wants it lazy, so
                // an unused booking never leaves a live URL lying about.
                'manage_token' => self::token(),

                'special_requests' => $data->specialRequests,
                'is_test' => $data->isTest,

                'terms_accepted_at' => $data->termsAcceptedAt,
                'ip_address' => $data->ipAddress,
                'user_agent' => $data->userAgent === null ? null : mb_substr($data->userAgent, 0, 500),
                ...$data->utmColumns(),
            ])->save();

            return $booking;
        });

        // Outside the reference retry, because a hold that fails must not be
        // retried with a fresh reference — the seats are gone either way, and
        // burning four more references on a full boat is noise in the one index
        // whose collisions we care about.
        if ($departure instanceof Departure && ! $isQuoteMode && ! $data->skipHold) {
            ($this->holdSeats)($booking, $departure);
        }

        return $booking;
    }

    /**
     * Insert, retrying only on a reference collision (BKG-3 item 4, ADR-0007).
     *
     * The retry is driven by the **unique constraint**, never by a prior
     * `SELECT`. A check-then-insert has a window between the two, and on SQLite
     * there is no locking to close it — so the check would pass, two bookings
     * would race, and one would fail anyway with an error nobody had planned
     * for.
     *
     * **Public since #89**, because {@see ImportBooking} writes a `bookings` row
     * too and needs the same retry. The alternative — a second copy of the loop
     * — would be a second answer to "what happens on a reference collision",
     * and the second copy is the one that swallows a foreign-key violation.
     *
     * @param  callable(string): Booking  $insert
     */
    public function insertWithReference(callable $insert): Booking
    {
        $attempts = (int) config('kaiki.booking.reference_attempts');
        $length = (int) config('kaiki.booking.reference_length');

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return DB::transaction(static fn (): Booking => $insert((string) BookingReference::generate($length)));
            } catch (QueryException $exception) {
                if (! self::isUniqueViolation($exception)) {
                    // Anything else is a real failure and rethrowing it
                    // immediately is the point: a retry loop that swallows every
                    // database error retries a bug five times and reports the
                    // last one.
                    throw $exception;
                }
            }
        }

        // ADR-0007: widen rather than keep trying. Five collisions at 24.3
        // million combinations is not luck, and a sixth character makes it
        // 729 million.
        return DB::transaction(static fn (): Booking => $insert((string) BookingReference::generate($length + 1)));
    }

    /**
     * Is this the unique-index violation we mean, on any driver?
     *
     * SQLite says `UNIQUE constraint failed`, MySQL says `Duplicate entry` with
     * SQLSTATE 23000. Matching the SQLSTATE alone would also catch a foreign-key
     * violation, which is a different bug and must not be retried.
     */
    private static function isUniqueViolation(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate entry')
            || str_contains($message, 'Integrity constraint violation: 1062');
    }

    /**
     * The departure a per-seat booking is for.
     *
     * Looked up by local date and time within the product, because that is what
     * a guest picked off a calendar — they never see a departure id, and CNV-8
     * keeps integer keys out of anything they could send.
     */
    private function resolveDeparture(int $productId, BookingDraftData $data): Departure
    {
        $query = Departure::query()
            ->where('product_id', $productId)
            ->whereDate('local_date', $data->date->toDateString());

        if ($data->startTime !== null) {
            $query->whereTime('local_time', $data->startTime);
        }

        $departure = $query->orderBy('local_time')->first();

        if (! $departure instanceof Departure) {
            throw ValidationException::withMessages([
                'date' => [trans('booking.draft.no_departure', ['date' => $data->date->toDateString()])],
            ]);
        }

        return $departure;
    }

    /**
     * The local trio and the UTC pair, always written together (§1.5).
     *
     * A per-seat booking copies them from its departure, which is the single
     * local-to-UTC authority (#26). A per-vessel one derives them from the
     * requested start, and the product's duration decides the end.
     *
     * @return array{local_date: string, local_time: string, starts_at_utc: Carbon, ends_at_utc: Carbon}
     */
    private function window(BookingDraftData $data, ?Departure $departure): array
    {
        if ($departure instanceof Departure) {
            return [
                'local_date' => $departure->local_date->toDateString(),
                'local_time' => (string) $departure->local_time,
                'starts_at_utc' => $departure->starts_at_utc,
                'ends_at_utc' => $departure->ends_at_utc,
            ];
        }

        $tenant = $data->product->tenant;
        $timezone = $tenant === null ? (string) config('kaiki.defaults.timezone') : $tenant->timezone;
        $time = $data->startTime ?? (string) config('kaiki.availability.operating_window.earliest');

        $start = Carbon::parse($data->date->toDateString() . ' ' . $time, $timezone)->utc();
        $hours = ($data->product->duration_minutes ?? 0) / 60 + $data->extraHours;

        return [
            'local_date' => $data->date->toDateString(),
            'local_time' => $time,
            'starts_at_utc' => $start,
            'ends_at_utc' => $start->copy()->addMinutes((int) round($hours * 60)),
        ];
    }

    /**
     * §3.1's frozen pax breakdown.
     *
     * The band's `code` and `counts_toward_capacity` are copied in, so a
     * manifest a year later can still say which of these four people occupied a
     * seat after the band has been renamed or deleted.
     *
     * @param  iterable<AgeBand>  $bands
     * @param  array<string, int>  $pax
     * @param  array<int, array<string, mixed>>  $priceLines  the price snapshot's own lines
     * @return list<array<string, mixed>>
     */
    private function paxBreakdown(iterable $bands, array $pax, array $priceLines = []): array
    {
        $lines = [];

        foreach ($priceLines as $line) {
            if (($line['kind'] ?? null) === 'pax' && isset($line['ref'])) {
                $lines[(string) $line['ref']] = $line;
            }
        }

        $breakdown = [];

        foreach ($bands as $band) {
            $quantity = $pax[$band->code] ?? 0;

            if ($quantity < 1) {
                continue;
            }

            $line = $lines[$band->code] ?? null;

            $breakdown[] = [
                // **`age_band_uuid`, not `age_band_id`** — corrected by #89.
                // §3.1 has documented this shape since M1 and the code wrote an
                // integer key instead, which CNV-8 forbids in any payload: the
                // snapshot is rendered straight into `GET /bookings/{uuid}`, so
                // it was a leak from the moment that endpoint existed. Found by
                // `BookingEndpointTest`'s recursive id scan rather than by
                // review, and nothing read the id back — `SaveAgeBands` says so
                // in as many words, that the snapshot exists *instead of* a live
                // join.
                'age_band_uuid' => $band->uuid,
                'code' => $band->code,
                // The frozen translation §3.1 asks for, "so the email renders
                // correctly forever". An operator renaming "Ενήλικας" next
                // season must not rewrite what a guest was shown last year.
                'label' => $this->frozenLabel($band),
                'qty' => $quantity,
                'min_age' => $band->min_age,
                'max_age' => $band->max_age,
                'counts_toward_capacity' => (bool) $band->counts_toward_capacity,
                // Taken from the price snapshot rather than recomputed, so the
                // two halves of the same booking cannot disagree — the same
                // reasoning `extrasSnapshot()` gives.
                'unit_price_cents' => (int) ($line['unit_price_cents'] ?? 0),
                'total_cents' => (int) ($line['total_cents'] ?? 0),
            ];
        }

        return $breakdown;
    }

    /**
     * The band's label, frozen per locale (§3.1).
     *
     * Read through `getTranslations()` rather than through the accessor, which
     * would resolve one locale and throw the other away — and the whole point
     * of freezing it is that a Greek guest's confirmation and an English
     * guest's ticket both still render years later.
     *
     * @return array<string, string>
     */
    private function frozenLabel(AgeBand $band): array
    {
        /** @var array<string, string> $labels */
        $labels = $band->getTranslations('label');

        return $labels;
    }

    /**
     * §3.2's frozen extras, lifted out of the price snapshot.
     *
     * Taken from the snapshot rather than recomputed, so the two cannot
     * disagree — a booking whose extras list says one thing and whose price
     * says another is a dispute nobody can settle.
     *
     * @param  array<string, mixed>  $snapshot
     * @return list<array<string, mixed>>
     */
    private function extrasSnapshot(array $snapshot): array
    {
        $lines = is_array($snapshot['lines'] ?? null) ? $snapshot['lines'] : [];

        return array_values(array_filter(
            $lines,
            static fn (mixed $line): bool => is_array($line) && ($line['kind'] ?? null) === 'extra',
        ));
    }

    /**
     * A 40-character URL token.
     *
     * `Str::random` is `random_bytes` underneath, so this is 40 characters of
     * base62 — well past what a bearer URL needs. The URL *is* the credential
     * (TOK-1), so it is generated the same way a secret is and never derived
     * from anything about the booking.
     */
    private static function token(): string
    {
        return Str::random(40);
    }
}
