<?php

declare(strict_types=1);

use App\Domain\Availability\LocalDateTimeResolver;
use App\Domain\Availability\Support\LocalDay;
use App\Enums\DepartureStatus;
use App\Exceptions\InconsistentDepartureTime;
use App\Models\Departure;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| A departure's three time columns always agree — spec CNV-3, AVL-13, AVL-21
|--------------------------------------------------------------------------
|
| `local_date`, `local_time` and `starts_at_utc` describe one moment. They are
| written by the generator, by manual creation and eventually by an import, and
| a mismatch is silent: a trip whose ticket says 09:00, whose iCal entry says
| 08:00, and whose manifest says something else again — discovered by a guest
| standing on the quay.
|
| The guard is a **comparison**, never a correction. Rewriting the local pair
| from the UTC instant would hide the bug that produced the mismatch, and on the
| October fall-back date such a "fix" would silently choose one of two valid
| answers.
|
*/

function departureTenant(callable $callback): mixed
{
    return Tenancy::forTenant(Tenant::factory()->create(['timezone' => 'Europe/Athens']), $callback);
}

it('saves a departure whose columns agree', function (): void {
    departureTenant(function (): void {
        $departure = Departure::factory()->at('2026-07-04', '09:00', 480)->create();

        expect($departure->local_date->toDateString())->toBe('2026-07-04')
            ->and($departure->local_time)->toBe('09:00:00')
            ->and($departure->starts_at_utc->toDateTimeString())->toBe('2026-07-04 06:00:00')
            // 480 minutes of absolute elapsed time (AVL-17).
            ->and($departure->ends_at_utc->toDateTimeString())->toBe('2026-07-04 14:00:00')
            ->and($departure->dst_ambiguous)->toBeFalse();
    });
})->group('fast');

it('refuses a departure whose local time disagrees with its UTC instant', function (): void {
    departureTenant(function (): void {
        // The exact bug: someone edits `local_time` and forgets the instant.
        $departure = Departure::factory()->at('2026-07-04', '09:00')->create();

        $departure->local_time = '10:00:00';
        $departure->save();
    });
})->throws(InconsistentDepartureTime::class)->group('fast');

it('refuses a departure whose local date disagrees with its UTC instant', function (): void {
    departureTenant(function (): void {
        $departure = Departure::factory()->at('2026-07-04', '09:00')->create();

        $departure->local_date = Carbon::parse('2026-07-05');
        $departure->save();
    });
})->throws(InconsistentDepartureTime::class)->group('fast');

it('refuses one built by hand with a plausible-looking UTC value', function (): void {
    departureTenant(function (): void {
        // Plausible is the point. Someone writing 09:00 local and 09:00 UTC has
        // made a three-hour mistake that nothing else in the system would
        // notice until a guest missed a boat.
        $template = Departure::factory()->at('2026-07-04', '09:00')->make();

        Departure::query()->create([
            ...$template->getAttributes(),
            'starts_at_utc' => '2026-07-04 09:00:00',
        ]);
    });
})->throws(InconsistentDepartureTime::class)->group('fast');

it('accepts both instants on the ambiguous October date', function (): void {
    // On the fall-back date the local pair renders identically from either
    // instant, so the consistency check passes for both — which is correct.
    // Which one was chosen is ADR-0016's tie-break, recorded in
    // `dst_ambiguous`, and not something a consistency check can second-guess.
    departureTenant(function (): void {
        $departure = Departure::factory()->at('2026-10-25', '03:30')->create();

        expect($departure->dst_ambiguous)->toBeTrue()
            // The earlier of the two, still at +03:00.
            ->and($departure->starts_at_utc->toDateTimeString())->toBe('2026-10-25 00:30:00')
            ->and($departure->local_time)->toBe('03:30:00');
    });
})->group('fast');

it('cannot be created at a local time that does not exist', function (): void {
    departureTenant(function (): void {
        // ADR-0016 Option A: never invent a departure at a time the operator
        // did not choose. The factory goes through the resolver, so the refusal
        // reaches even a test that meant to write one.
        Departure::factory()->at('2026-03-29', '03:30')->create();
    });
})->throws(LogicException::class)->group('fast');

it('refuses two departures for the same product at the same instant', function (): void {
    departureTenant(function (): void {
        // `departures_tenant_prod_start_uq` is what makes generation
        // idempotent: re-running the nightly job can never duplicate a row.
        $first = Departure::factory()->at('2026-07-04', '09:00')->create();

        Departure::factory()->at('2026-07-04', '09:00')->create([
            'product_id' => $first->product_id,
            'vessel_id' => $first->vessel_id,
        ]);
    });
})->throws(QueryException::class)->group('fast');

it('allows the same local time twice on the fall-back date', function (): void {
    // The reason the unique index is on `starts_at_utc` rather than on the
    // local pair: on 25 October the same local time is genuinely two different
    // departures, and a unique index on the local pair would refuse the second.
    departureTenant(function (): void {
        $first = Departure::factory()->at('2026-10-25', '03:30')->create();

        $later = Departure::factory()->make();
        $starts = Carbon::parse('2026-10-25 01:30:00', 'UTC');

        Departure::query()->create([
            ...$later->getAttributes(),
            'product_id' => $first->product_id,
            'vessel_id' => $first->vessel_id,
            'local_date' => '2026-10-25',
            'local_time' => '03:30:00',
            'starts_at_utc' => $starts,
            'ends_at_utc' => LocalDateTimeResolver::endsAt($starts, 480),
            'dst_ambiguous' => true,
        ]);

        expect(Departure::query()->where('local_time', '03:30:00')->count())->toBe(2);
    });
})->group('fast');

it('counts available seats as capacity minus sold minus held', function (): void {
    // §2.4, AVL-22.3, AVL-24: the two counters are **disjoint and additive**,
    // not nested. Keeping holds out of `seats_sold` is what stops an unpaid
    // draft flipping the departure to `guaranteed` and emailing every guest
    // that the trip is confirmed.
    departureTenant(function (): void {
        $departure = Departure::factory()->withSeats(sold: 3, held: 2)->create(['capacity' => 12]);

        expect($departure->seatsAvailable())->toBe(7);
    });
})->group('fast');

it('never reports negative availability', function (): void {
    departureTenant(function (): void {
        $departure = Departure::factory()->withSeats(sold: 12, held: 3)->create(['capacity' => 12]);

        expect($departure->seatsAvailable())->toBe(0);
    });
})->group('fast');

it('measures the minimum against sold seats alone', function (): void {
    // §4.2: a hold is not a commitment, and a departure that flipped to
    // guaranteed on unpaid drafts would email guests a promise the operator
    // never made.
    departureTenant(function (): void {
        $departure = Departure::factory()->withSeats(sold: 3, held: 5)->create(['min_pax' => 4]);

        expect($departure->meetsMinimum())->toBeFalse();
    });
})->group('fast');

it('finds the departures of a local day by UTC comparison', function (): void {
    // AVL-13: interval logic compares UTC columns. On a 25-hour day the local
    // column and the UTC window are exactly where the two would disagree.
    departureTenant(function (): void {
        $product = Product::factory()->create();
        $vessel = Vessel::factory()->create();

        Departure::factory()->at('2026-10-25', '09:00')->create([
            'product_id' => $product->getKey(),
            'vessel_id' => $vessel->getKey(),
        ]);

        Departure::factory()->at('2026-10-26', '09:00')->create([
            'product_id' => $product->getKey(),
            'vessel_id' => $vessel->getKey(),
        ]);

        $day = LocalDay::of('2026-10-25', 'Europe/Athens');

        expect($day->hours())->toBe(25)
            ->and(Departure::query()->onLocalDay($day)->count())->toBe(1);
    });
})->group('fast');

it('treats scheduled and guaranteed as sellable and cancelled as not', function (): void {
    departureTenant(function (): void {
        Departure::factory()->create();
        Departure::factory()->at('2026-07-05', '10:00')->guaranteed()->create();
        Departure::factory()->at('2026-07-06', '11:00')->cancelled()->create();

        expect(Departure::query()->sellable()->count())->toBe(2)
            ->and(DepartureStatus::Cancelled->isSellable())->toBeFalse()
            ->and(DepartureStatus::Cancelled->occupiesVessel())->toBeFalse()
            ->and(DepartureStatus::Completed->occupiesVessel())->toBeTrue();
    });
})->group('fast');
