<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Data\BookingDraftData;
use App\Domain\Booking\Support\GuestTokenResolver;
use App\Domain\Booking\Support\SeatCommitment;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\GuestDetailsStatus;
use App\Events\BookingConfirmed;
use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\Departure;
use Illuminate\Support\Str;

/**
 * A booking that happened somewhere else (spec BKG-34).
 *
 * > Imported bookings (source `import`) are created in **`confirmed`** with a
 * > synthetic price snapshot derived from the source data, are flagged as
 * > imported in the panel, and **MUST NOT trigger confirmation notifications,
 * > invoices or webhooks**.
 *
 * ## The last clause is the whole design, and it is why this is not `ConfirmBooking`
 *
 * An operator migrating a season's bookings out of a spreadsheet would
 * otherwise email four hundred guests a confirmation for a trip they booked in
 * March, text them all, issue four hundred myDATA invoices and fire four
 * hundred webhooks at whatever their old system still has running. That is not
 * a bad first day — it is the kind of first day an operator leaves over.
 *
 * The only way to guarantee it is **not to dispatch the event**. Every one of
 * BKG-13's nine listeners hangs off {@see BookingConfirmed}, so a flag they
 * each had to check would be nine places to remember and nine places for the
 * tenth listener to forget. `ImportedBookingTest` fakes the queue and asserts
 * that *nothing* was dispatched, which is an assertion about the absence of a
 * line rather than about the behaviour of nine.
 *
 * ## Confirmed, and the seats are real
 *
 * An imported booking occupies its seats — it describes a trip somebody is
 * actually going on. So `seats_sold` is incremented the ordinary way, through
 * the same counter every other committed booking writes, and an import that
 * oversells a departure is a data problem the operator has to see rather than
 * a number quietly out of step.
 *
 * ## "Synthetic price snapshot derived from the source data"
 *
 * Not a recomputation. The guest paid what they paid, under a rate plan that
 * may no longer exist, in a season that has been edited since — running today's
 * pricing engine over last March's booking would produce a total that is
 * confidently wrong. The snapshot records the figures as given and says
 * `"source": "import"` so nobody later mistakes it for a derivation.
 */
final class ImportBooking
{
    public function __construct(private readonly CreateBookingDraft $drafts) {}

    /**
     * @param  int  $totalCents  what the guest was actually charged, at the source
     * @param  int  $paidCents  how much of it has been received
     * @param  Departure|null  $departure  the sailing, for `per_seat` imports
     * @param  array<string, mixed>  $sourceData  whatever the source system called this booking
     */
    public function __invoke(
        BookingDraftData $data,
        int $totalCents,
        int $paidCents,
        ?Departure $departure = null,
        array $sourceData = [],
    ): Booking {
        $product = $data->product;
        $bands = $product->ageBands;

        $pax = $data->paxByCode;
        $paxTotal = array_sum($pax);

        $counted = 0;

        foreach ($bands as $band) {
            if ($band->counts_toward_capacity) {
                $counted += $pax[$band->code] ?? 0;
            }
        }

        // The same collision retry every other booking insert uses (BKG-3,
        // ADR-0007) — an imported reference collides exactly as often as a
        // booked one, and a second copy of that loop is the copy that swallows
        // a foreign-key violation.
        return $this->drafts->insertWithReference(function (string $reference) use ($data, $product, $departure, $pax, $paxTotal, $counted, $totalCents, $paidCents, $sourceData): Booking {
            $booking = new Booking;

            $booking->forceFill([
                'uuid' => (string) Str::uuid(),
                'reference' => $reference,
                'product_id' => $product->getKey(),
                'vessel_id' => $departure === null ? $product->vessel_id : $departure->vessel_id,
                'departure_id' => $departure?->getKey(),
                'mode' => $product->mode,
                // BKG-34's first word.
                'status' => BookingStatus::Confirmed,
                'source' => BookingSource::Import,
                'locale' => $data->locale,

                'local_date' => $departure === null ? $data->date->toDateString() : $departure->local_date->toDateString(),
                'local_time' => $departure === null
                    ? ($data->startTime ?? $product->default_start_time)
                    : (string) $departure->local_time,
                'starts_at_utc' => $departure === null ? $data->date->copy() : $departure->starts_at_utc,
                'ends_at_utc' => $departure === null
                    ? $data->date->copy()->addMinutes($product->duration_minutes)
                    : $departure->ends_at_utc,

                'guest_name' => trim($data->guestName),
                'guest_email' => mb_strtolower(trim($data->guestEmail)),
                'guest_phone' => $data->guestPhone,
                'guest_country' => $data->guestCountry,

                'pax_total' => $paxTotal,
                'pax_capacity_total' => $counted,
                'pax_breakdown' => $this->paxBreakdown($pax),
                'extras_snapshot' => [],

                'policy_snapshot' => $product->cancellationPolicy?->toSnapshotData()->toArray(),
                'price_snapshot' => $this->syntheticSnapshot($totalCents, $sourceData),

                'subtotal_cents' => $totalCents,
                'extras_cents' => 0,
                'discount_cents' => 0,
                'total_cents' => $totalCents,
                'deposit_cents' => 0,
                'paid_cents' => $paidCents,
                'balance_cents' => max(0, $totalCents - $paidCents),
                'refunded_cents' => 0,

                // Zero rather than a guess. VAT on a booking taken elsewhere is
                // whatever the other system charged, and inventing a split here
                // would put a number the operator never agreed to into a myDATA
                // invoice.
                'vat_rate_bp' => 0,
                'vat_category' => '',
                'vat_cents' => 0,

                'guest_details_status' => GuestDetailsStatus::NotRequired,
                'manage_token' => GuestTokenResolver::mint(),

                'confirmed_at' => now(),
                'is_test' => $data->isTest,
            ])->save();

            $this->createManifestRows($booking, $pax);

            // An imported booking describes a trip somebody is actually going
            // on, so its seats are sold. Through the same statement every other
            // committed booking writes — `seats_sold` has one writer for the
            // reason `CLAUDE.md`'s first invariant gives, and an importer with
            // its own arithmetic would be a second.
            //
            // Deliberately **not** refused when it oversells. An operator
            // migrating last season's spreadsheet needs their history to land;
            // half an import is worse than an import that shows a departure
            // over its capacity, which the departure list already surfaces.
            // The return value is therefore ignored on purpose rather than
            // forgotten — an import is a statement about what happened, not a
            // request for permission.
            if ($departure instanceof Departure) {
                SeatCommitment::commit($departure, $counted);
            }

            return $booking;
        });
    }

    /**
     * The manifest rows a confirmed booking always has (§2.5).
     *
     * Created here because they are created at confirmation everywhere else,
     * and an imported booking that had none would be a confirmed booking the
     * check-in page cannot show and the manifest export skips — a hole that
     * would only appear on the morning of the trip.
     *
     * @param  array<string, int>  $pax
     */
    private function createManifestRows(Booking $booking, array $pax): void
    {
        $position = 1;

        foreach ($pax as $code => $quantity) {
            for ($i = 0; $i < $quantity; $i++) {
                BookingGuest::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'booking_id' => $booking->getKey(),
                    'age_band_code' => $code,
                    'position' => $position,
                    'is_lead' => $position === 1,
                    'full_name' => $position === 1 ? $booking->guest_name : null,
                    'ticket_code' => strtoupper(Str::random(24)),
                ]);

                $position++;
            }
        }
    }

    /**
     * A snapshot that says what it is.
     *
     * `"source": "import"` is not decoration. §3.4's snapshot is read later by
     * the invoice, by the refund calculator and by whoever is trying to work
     * out why a booking's total is what it is — and every one of them behaves
     * differently once they know the figures were given rather than derived.
     *
     * @param  array<string, mixed>  $sourceData
     * @return array<string, mixed>
     */
    private function syntheticSnapshot(int $totalCents, array $sourceData): array
    {
        return [
            'source' => 'import',
            'computed_at' => now()->toIso8601ZuluString(),
            'currency' => 'EUR',
            'subtotal_cents' => $totalCents,
            'extras_cents' => 0,
            'discount_cents' => 0,
            'total_cents' => $totalCents,
            'lines' => [[
                'kind' => 'imported',
                'label' => 'Imported booking',
                'qty' => 1,
                'unit_price_cents' => $totalCents,
                'total_cents' => $totalCents,
            ]],
            'vat' => ['rate_bp' => 0, 'vat_cents' => 0, 'included' => true],
            // Whatever the old system called this booking, kept verbatim. An
            // operator reconciling an import a month later needs the other
            // system's own reference, and it exists nowhere else.
            'imported_from' => $sourceData,
        ];
    }

    /**
     * @param  array<string, int>  $pax
     * @return list<array<string, mixed>>
     */
    private function paxBreakdown(array $pax): array
    {
        $lines = [];

        foreach ($pax as $code => $quantity) {
            $lines[] = [
                'code' => $code,
                'label' => $code,
                'qty' => $quantity,
                'counts_toward_capacity' => true,
                'unit_price_cents' => 0,
                'total_cents' => 0,
            ];
        }

        return $lines;
    }
}
