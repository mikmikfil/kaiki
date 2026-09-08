<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Availability\Actions\HoldSeats;
use App\Domain\Booking\Actions\CancelBooking;
use App\Domain\Booking\Actions\ConfirmBooking;
use App\Domain\Booking\Actions\CreateBookingDraft;
use App\Domain\Booking\Actions\CreateManualBooking;
use App\Domain\Booking\Actions\RecordManualPayment;
use App\Domain\Booking\Data\BookingDraftData;
use App\Domain\Pricing\Actions\ComputePrice;
use App\Enums\BookingSource;
use App\Enums\CancelledBy;
use App\Enums\CancelReason;
use App\Enums\PaymentGatewayName;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * A season's worth of bookings, so the panel has something to be about.
 *
 * ## Why a demo needs these and not just departures
 *
 * Every operations screen built in M5 reads bookings rather than products: the
 * dashboard figures, the fleet strip, «Χρειάζονται προσοχή», the manifests, the
 * exports, the departure reconciliation, the weather panel's "5 αναχωρήσεις · 0
 * επιβάτες". With an empty `bookings` table all of them render their empty
 * state, which is the one state nobody needs to look at — and the arithmetic on
 * two rows reads as arithmetic, while twenty rows read as a business.
 *
 * ## Through the real Action, never a factory
 *
 * {@see CreateManualBooking} — which means {@see CreateBookingDraft},
 * which means {@see ComputePrice} and
 * {@see HoldSeats}. So every row here has a
 * real reference, a real VAT split, a frozen cancellation policy and a
 * `seats_sold` on its departure that actually adds up.
 *
 * A factory would have been three lines and would have produced bookings whose
 * totals disagree with their own line items — which is worse than no demo data,
 * because the first thing anybody does with a number on a dashboard is check it
 * against the row underneath.
 *
 * ## Spread across the season on purpose
 *
 * Past departures give the reconciliation screen and the completed-trip figures
 * something to reconcile. Today fills the fleet strip. The next fortnight is
 * what the calendar and the weather panel read. A demo that booked only future
 * trips would leave half the product looking broken.
 *
 * ## Deterministic
 *
 * No faker, no `rand()`. The same names, parties and dates on every run, for
 * the reason {@see DemoCatalogSeeder} gives: a demo that produced different
 * numbers each time makes every screenshot and every conversation about it
 * unreliable.
 */
class DemoBookingSeeder extends Seeder
{
    /**
     * Roughly how many to aim for per operator.
     *
     * Enough that a list paginates and a chart has a shape; few enough that a
     * re-seed is seconds rather than a minute.
     */
    private const TARGET = 60;

    /**
     * Guests, in the mix a Greek day-boat operator actually sees.
     *
     * Greek names, and the nationalities that fill the boats between May and
     * September. Names matter more than they look: a manifest, an e-ticket and
     * a passenger CSV all render these, and a screen full of "Test User 1"
     * proves nothing about how the real thing reads.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private const GUESTS = [
        ['Γιώργος Παπαδόπουλος', 'g.papadopoulos@example.com', '+306941000101', 'GR'],
        ['Μαρία Οικονόμου', 'm.oikonomou@example.com', '+306941000102', 'GR'],
        ['Ελένη Νικολάου', 'e.nikolaou@example.com', '+306941000103', 'GR'],
        ['Δημήτρης Αντωνίου', 'd.antoniou@example.com', '+306941000104', 'GR'],
        ['Κατερίνα Σταύρου', 'k.stavrou@example.com', '+306941000105', 'GR'],
        ['Anna Schmidt', 'anna.schmidt@example.com', '+491701000106', 'DE'],
        ['Thomas Müller', 't.mueller@example.com', '+491701000107', 'DE'],
        ['Sophie Dubois', 's.dubois@example.com', '+336010000108', 'FR'],
        ['James Whitfield', 'j.whitfield@example.com', '+447700900109', 'GB'],
        ['Emily Carter', 'e.carter@example.com', '+447700900110', 'GB'],
        ['Marco Rossi', 'm.rossi@example.com', '+393401000111', 'IT'],
        ['Giulia Ferrari', 'g.ferrari@example.com', '+393401000112', 'IT'],
        ['Lars Andersen', 'l.andersen@example.com', '+451000113', 'DK'],
        ['Sanne de Vries', 's.devries@example.com', '+316100001114', 'NL'],
        ['Michael O\'Brien', 'm.obrien@example.com', '+353851000115', 'IE'],
    ];

    /**
     * Party shapes, cycled.
     *
     * Two adults is the common case and everything else is a case some screen
     * has to handle: the family with an infant that occupies a manifest line
     * and no seat, the solo traveller, and the group of six that is most of a
     * small boat.
     *
     * @var list<array<string, int>>
     */
    private const PARTIES = [
        ['adult' => 2],
        ['adult' => 2, 'child' => 1],
        ['adult' => 4],
        ['adult' => 1],
        ['adult' => 2, 'child' => 2, 'infant' => 1],
        ['adult' => 6],
        ['adult' => 3],
        ['adult' => 2, 'infant' => 1],
    ];

    public function run(): void
    {
        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            Tenancy::forTenant($tenant, function () use ($tenant): void {
                $this->seedFor($tenant);
            });
        }
    }

    private function seedFor(Tenant $tenant): void
    {
        $existing = Booking::query()->count();

        if ($existing >= self::TARGET) {
            $this->command?->info("  {$tenant->slug}: {$existing} bookings already, leaving them alone.");

            return;
        }

        $departures = $this->bookableDepartures();

        if ($departures === []) {
            // Ionian Sunset is deliberately thin — a trial account with nothing
            // configured is a real state the panel has to render. No products,
            // no departures, no bookings, and that is correct rather than a
            // failure to seed.
            $this->command?->info("  {$tenant->slug}: nothing bookable, skipped.");

            return;
        }

        $made = 0;
        $cancelled = 0;
        $index = $existing;

        foreach ($departures as $departure) {
            if ($existing + $made >= self::TARGET) {
                break;
            }

            // One or two parties per departure, so some sail half empty and
            // some are nearly full — the difference the fleet strip and the
            // «Χρειάζονται προσοχή» minimum-numbers rule are both about.
            $parties = $index % 3 === 0 ? 2 : 1;

            for ($n = 0; $n < $parties; $n++, $index++) {
                $booking = $this->book($departure, $index);

                if ($booking === null) {
                    continue;
                }

                $made++;

                // Roughly one in nine cancelled. Not for realism's sake: the
                // refund figures, the cancellation reasons on the reconciliation
                // screen and the "excluded from the count" rules in the exports
                // and the weather panel are all invisible without them.
                if ($index % 9 === 4) {
                    $this->cancel($booking);
                    $cancelled++;
                }
            }
        }

        $this->command?->info("  {$tenant->slug}: {$made} bookings ({$cancelled} of them cancelled).");
    }

    /**
     * Departures worth booking, oldest first.
     *
     * A fortnight back and a fortnight forward. The past half is what the
     * reconciliation screen and the completed-trip figures read; the future
     * half is what the calendar, the manifests and the weather panel read.
     *
     * @return list<Departure>
     */
    private function bookableDepartures(): array
    {
        $products = Product::query()
            ->whereHas('ageBands')
            ->pluck('id')
            ->all();

        if ($products === []) {
            return [];
        }

        return Departure::query()
            ->whereIn('product_id', $products)
            ->whereBetween('local_date', [
                Carbon::now()->subDays(14)->toDateString(),
                Carbon::now()->addDays(14)->toDateString(),
            ])
            ->orderBy('local_date')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * One booking, or null if the engine refused it.
     *
     * A refusal is expected and is not an error: the party may not fit what is
     * left on the boat, and the availability engine saying so is the engine
     * working. Swallowing it here rather than letting it stop the seed means a
     * full boat is one fewer booking instead of a broken `db:seed`.
     */
    private function book(Departure $departure, int $index): ?Booking
    {
        [$name, $email, $phone, $country] = self::GUESTS[$index % count(self::GUESTS)];
        $party = self::PARTIES[$index % count(self::PARTIES)];

        $product = $departure->product;

        if (! $product instanceof Product) {
            return null;
        }

        // Only the bands this product actually has. A `child` count against a
        // product with adults only is rejected by the draft, correctly.
        $codes = $product->ageBands->pluck('code')->all();
        $pax = array_intersect_key($party, array_flip($codes));

        if (($pax['adult'] ?? 0) < 1) {
            return null;
        }

        $data = new BookingDraftData(
            product: $product,
            date: Carbon::parse($departure->local_date),
            guestName: $name,
            guestEmail: $email,
            guestPhone: $phone,
            guestCountry: $country,
            locale: $country === 'GR' ? 'el' : 'en',
            source: BookingSource::Manual,
            paxByCode: $pax,
            startTime: $departure->local_time,
            termsAcceptedAt: Carbon::now(),
        );

        try {
            // Four states in turn, because each one is a different screen.
            //
            //   0  cash, settled            — the takings figure
            //   1  bank transfer, settled   — the same, through the other tender
            //   2  deposit only             — «Οφείλονται σε εσάς», and the
            //                                 balance-due reminders, both of
            //                                 which read zero on a demo where
            //                                 everything is paid in full
            //   3  nothing                  — an unpaid hold, which is what the
            //                                 expiring-holds row of
            //                                 «Χρειάζονται προσοχή» is about
            $tender = $index % 2 === 0 ? PaymentGatewayName::Cash : PaymentGatewayName::BankTransfer;

            $booking = app(CreateManualBooking::class)(
                $data,
                paidBy: $index % 4 < 2 ? $tender : null,
            );

            if ($index % 4 === 2) {
                $this->payDeposit($booking, $tender);
            }

            return $booking;
        } catch (Throwable $e) {
            $this->command?->warn("    skipped one: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Part of it, which confirms the booking and leaves the rest owing.
     *
     * {@see RecordManualPayment} derives the kind from what is already paid, so
     * this lands as a deposit rather than a `Full` that would double the amount
     * every later report reads. A booking with no deposit configured pays half,
     * rounded down — the point is a non-zero balance, not a particular one.
     */
    private function payDeposit(Booking $booking, PaymentGatewayName $tender): void
    {
        $amount = $booking->deposit_cents > 0
            ? $booking->deposit_cents
            : intdiv($booking->total_cents, 2);

        if ($amount < 1 || $amount >= $booking->total_cents) {
            return;
        }

        try {
            app(RecordManualPayment::class)($booking, $amount, $tender, reference: 'DEMO');

            // A part payment does not confirm on its own, and should not: the
            // engine leaves that decision to whoever took the money. An
            // operator who has a deposit in hand confirms the seat, so the
            // demo does the same — otherwise these sit as drafts and
            // «Οφείλονται σε εσάς», which counts confirmed bookings, stays at
            // zero however many deposits are on the table.
            app(ConfirmBooking::class)($booking->refresh());
        } catch (Throwable $e) {
            $this->command?->warn("    could not take a deposit: {$e->getMessage()}");
        }
    }

    private function cancel(Booking $booking): void
    {
        try {
            app(CancelBooking::class)(
                $booking,
                reason: CancelReason::GuestRequest,
                by: CancelledBy::Guest,
            );
        } catch (Throwable $e) {
            $this->command?->warn("    could not cancel: {$e->getMessage()}");
        }
    }
}
