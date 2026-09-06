<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\AcceptQuote;
use App\Domain\Booking\Actions\BuildQuote;
use App\Domain\Booking\Actions\DeclineQuote;
use App\Domain\Booking\Actions\ExpireQuotes;
use App\Domain\Booking\Actions\SendQuote;
use App\Enums\BlockReason;
use App\Enums\BookingStatus;
use App\Enums\QuoteStatus;
use App\Models\Quote;
use App\Models\QuoteLineItem;
use App\Models\VesselBlock;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Tests\Support\Booking\QuoteScenario;

/*
|--------------------------------------------------------------------------
| BKG-25 (RESOLVED), §4.4: a quote is not a hold
|--------------------------------------------------------------------------
|
| The requirement carries its own reason: *"otherwise a quote request would
| block a vessel indefinitely."* An operator with a dozen open enquiries would
| have a boat nobody could buy, and nothing in the panel would say why.
|
| So the hold is **opt-in at the moment of sending**, it is an ordinary
| `vessel_blocks` row an operator can see in their calendar, and its expiry is
| **equal** to the quote's `valid_until` — not close to it, not separately
| entered. Two dates that are supposed to match and are typed in twice are two
| dates that will not match.
|
| §4.4's own note calls the acceptance re-check *"the single most important
| behaviour to get right in quote mode"*, and it has its own test at the bottom
| of this file.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    Carbon::setTestNow('2026-06-01 09:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('holds nothing while the booking is only asking', function (): void {
    [$tenant, $booking] = QuoteScenario::requested();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        app(BuildQuote::class)($booking);

        expect(VesselBlock::query()->count())->toBe(0)
            // The other half of "holds nothing": no hold column either. A
            // `quote_requested` booking is not a draft with an expiry.
            ->and($booking->refresh()->hold_expires_at)->toBeNull()
            ->and($booking->occupiesVesselWindow())->toBeFalse();
    });
})->group('fast');

it('holds nothing when the quote is sent without the opt-in', function (): void {
    [$tenant, $booking, $quote] = QuoteScenario::drafted();

    Tenancy::forTenant($tenant, function () use ($quote): void {
        app(SendQuote::class)($quote);

        // The default. An operator who does not ask for a hold does not get
        // one, which is the whole of BKG-25's resolution.
        expect(VesselBlock::query()->count())->toBe(0);
    });
})->group('fast');

it('holds the window on the explicit opt-in, expiring exactly with the quote', function (): void {
    [$tenant, $booking, $quote] = QuoteScenario::drafted();

    Tenancy::forTenant($tenant, function () use ($booking, $quote): void {
        $sent = app(SendQuote::class)($quote, holdVessel: true);

        $block = VesselBlock::query()->sole();

        expect($block->reason)->toBe(BlockReason::Manual)
            ->and($block->booking_id)->toBe($booking->getKey())
            ->and($block->vessel_id)->toBe($booking->vessel_id)
            // **Equal**, to the second. This is the assertion BKG-25's
            // resolution rests on: a hold that outlived its quote would be the
            // indefinite block the requirement exists to prevent.
            ->and($block->ends_at_utc->toIso8601String())
            ->toBe($sent->valid_until->toIso8601String());
    });
})->group('fast');

it('does not leave the first hold behind when the quote is revised', function (): void {
    [$tenant, $booking, $first] = QuoteScenario::drafted();

    Tenancy::forTenant($tenant, function () use ($booking, $first): void {
        app(SendQuote::class)($first, holdVessel: true);

        $second = app(BuildQuote::class)($booking->refresh());

        QuoteLineItem::factory()->create([
            'quote_id' => $second->getKey(),
            'unit_price_cents' => 89000,
            'total_cents' => 89000,
        ]);

        app(BuildQuote::class)->recomputeTotals($second);
        $second->forceFill(['valid_until' => now()->addDays(21)])->save();

        app(SendQuote::class)($second->refresh(), holdVessel: true);

        // One block, refreshed — not two. A second row would take the boat off
        // sale until the *later* of two dates with nothing saying which.
        $block = VesselBlock::query()->sole();

        expect($block->ends_at_utc->toDateString())->toBe(now()->addDays(21)->toDateString());
    });
})->group('fast');

it('gives the boat back the moment the guest declines', function (): void {
    [$tenant, $booking, $quote] = QuoteScenario::drafted();

    Tenancy::forTenant($tenant, function () use ($quote): void {
        app(SendQuote::class)($quote, holdVessel: true);

        app(DeclineQuote::class)($quote->refresh(), 'Not this year');

        // A guest who has said no has said no. Leaving the block to expire with
        // `valid_until` would keep the boat unsellable for the rest of a
        // fortnight over a decision made on day one.
        expect(VesselBlock::query()->count())->toBe(0);
    });
})->group('fast');

it('gives the boat back when the quote expires', function (): void {
    [$tenant, $booking, $quote] = QuoteScenario::drafted();

    Tenancy::forTenant($tenant, function () use ($quote): void {
        app(SendQuote::class)($quote, holdVessel: true);
    });

    Carbon::setTestNow(now()->addDays(8));

    app(ExpireQuotes::class)();

    Tenancy::forTenant($tenant, function (): void {
        expect(VesselBlock::query()->count())->toBe(0);
    });
})->group('fast');

it('releases its own hold before re-checking, rather than blocking on itself', function (): void {
    [$tenant, $booking, $quote] = QuoteScenario::drafted();

    Tenancy::forTenant($tenant, function () use ($quote): void {
        app(SendQuote::class)($quote, holdVessel: true);

        // The perfect little deadlock this guards against: a quote that held
        // the boat and then refused to let the guest accept because the boat
        // was held.
        $accepted = app(AcceptQuote::class)($quote->refresh());

        expect($accepted->status)->toBe(BookingStatus::PendingPayment)
            ->and(VesselBlock::query()->count())->toBe(0);
    });
})->group('fast');

it('refuses acceptance when somebody else took the boat, and keeps the quote alive', function (): void {
    [$tenant, $booking, $quote] = QuoteScenario::drafted();

    Tenancy::forTenant($tenant, function () use ($booking, $quote): void {
        app(SendQuote::class)($quote);

        // A quote is not a hold, so the boat was on sale the whole time the
        // guest was thinking. Somebody else booked it — a real competing block
        // rather than a flag, because the answer has to come from the same
        // reader the calendar uses.
        VesselBlock::factory()->create([
            'vessel_id' => $booking->vessel_id,
            'starts_at_utc' => $booking->starts_at_utc,
            'ends_at_utc' => $booking->ends_at_utc,
            'local_date' => $booking->local_date,
            'local_end_date' => $booking->local_date,
            'reason' => BlockReason::Maintenance,
        ]);

        expect(fn () => app(AcceptQuote::class)($quote->refresh()))
            ->toThrow(RuntimeException::class, 'vessel_unavailable');

        // **The quote stays `sent`**, which is §4.4's own instruction: the
        // operator can re-quote another date rather than rebuilding the offer,
        // and the guest is told the date has gone rather than that something
        // broke.
        expect($quote->refresh()->status)->toBe(QuoteStatus::Sent)
            ->and($booking->refresh()->status)->toBe(BookingStatus::QuoteSent);
    });
})->group('fast');

it('rolls the hold release back when acceptance fails', function (): void {
    [$tenant, $booking, $quote] = QuoteScenario::drafted();

    Tenancy::forTenant($tenant, function () use ($booking, $quote): void {
        app(SendQuote::class)($quote, holdVessel: true);

        // A second boat-blocking row, so the re-check refuses even after the
        // quote's own hold is released.
        VesselBlock::factory()->create([
            'vessel_id' => $booking->vessel_id,
            'starts_at_utc' => $booking->starts_at_utc,
            'ends_at_utc' => $booking->ends_at_utc,
            'local_date' => $booking->local_date,
            'local_end_date' => $booking->local_date,
            'reason' => BlockReason::Maintenance,
        ]);

        expect(fn () => app(AcceptQuote::class)($quote->refresh()))->toThrow(RuntimeException::class);

        // The operator's hold survives a race the guest did not cause: the
        // release happened inside the transaction, so a refusal rolled it back
        // with everything else.
        expect(VesselBlock::query()->where('booking_id', $booking->getKey())->count())->toBe(1);
    });
})->group('fast');

it('keeps the quote out of the availability engine entirely while it is open', function (): void {
    [$tenant, $booking, $quote] = QuoteScenario::drafted();

    Tenancy::forTenant($tenant, function () use ($booking, $quote): void {
        app(SendQuote::class)($quote);

        // Nothing about an open quote occupies a boat: not the booking, not the
        // quote row, not a counter. `Quote` has no relation that could reach a
        // departure and no column a counter reads.
        expect($booking->refresh()->occupiesVesselWindow())->toBeFalse()
            ->and(Quote::query()->sole()->status)->toBe(QuoteStatus::Sent)
            ->and(VesselBlock::query()->count())->toBe(0);
    });
})->group('fast');
