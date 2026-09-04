<?php

declare(strict_types=1);

use App\Domain\Availability\Actions\CreateManualDeparture;
use App\Domain\Availability\Support\DepartureConflictFinder;
use App\Domain\Availability\VesselCalendar;
use App\Enums\DepartureStatus;
use App\Models\Departure;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Manual departures and the conflict warning — spec AVL-11, AVL-12, AVL-52
|--------------------------------------------------------------------------
|
| AVL-11 is deliberately a **warning**, not a block: operators legitimately
| create overlapping zero-sold departures for two products on the same boat and
| let the bookings pick a winner. Blocking it would be wrong. Leaving it silent
| would produce departures that quietly stop being sellable the moment the first
| seat is committed elsewhere.
|
| AVL-12's last sentence is the exception, and it is a hard one: *"Same-product
| overlap is additionally blocked by validation at creation time."* One trip
| sold twice on one boat is a mistake rather than a strategy.
|
*/

function conflictTenant(callable $callback): mixed
{
    Queue::fake();

    return Tenancy::forTenant(Tenant::factory()->create(['timezone' => 'Europe/Athens']), $callback);
}

/** A per-seat product on a boat with the default 60-minute turnaround. */
function conflictProduct(int $durationMinutes = 240): Product
{
    $vessel = Vessel::factory()->create(['capacity_max' => 30, 'turnaround_buffer_minutes' => 60]);

    return Product::factory()->create([
        'vessel_id' => $vessel->getKey(),
        'max_pax' => 12,
        'min_pax' => 4,
        'duration_minutes' => $durationMinutes,
    ]);
}

/** @param array<string, mixed> $attributes */
function createManual(Product $product, array $attributes = [], bool $confirmed = false): Departure
{
    return app(CreateManualDeparture::class)($product, array_merge([
        'local_date' => '2026-07-04',
        'local_time' => '09:00',
    ], $attributes), $confirmed);
}

it('creates a one-off departure with no schedule rule', function (): void {
    conflictTenant(function (): void {
        // §2.4 has no `is_manual` column — the absence of a rule is the fact.
        $departure = createManual(conflictProduct());

        expect($departure->schedule_rule_id)->toBeNull()
            ->and($departure->local_time)->toBe('09:00:00')
            ->and($departure->starts_at_utc->toDateTimeString())->toBe('2026-07-04 06:00:00')
            ->and($departure->capacity)->toBe(12);
    });
})->group('fast');

it('caps capacity at the vessel certificate', function (): void {
    conflictTenant(function (): void {
        // An operator typing 40 into a one-off for a boat licensed for 30 has
        // made a mistake the port authority would find. The generator resolves
        // this; a manual departure is exactly where it would otherwise slip
        // through.
        expect(createManual(conflictProduct(), ['capacity' => 40])->capacity)->toBe(30);
    });
})->group('fast');

it('warns about a different product on the same boat, and creates it when confirmed', function (): void {
    conflictTenant(function (): void {
        $first = conflictProduct();
        $second = Product::factory()->create([
            'vessel_id' => $first->vessel_id,
            'max_pax' => 12,
            'duration_minutes' => 240,
        ]);

        createManual($first);

        // Unconfirmed: refused, with the warning.
        expect(fn () => createManual($second))->toThrow(ValidationException::class);

        // Confirmed: created. AVL-11 says these may coexist.
        $confirmed = createManual($second, [], confirmed: true);

        expect($confirmed->exists)->toBeTrue()
            ->and(Departure::query()->count())->toBe(2);
    });
})->group('fast');

it('refuses the same product at an overlapping time, confirmed or not', function (): void {
    conflictTenant(function (): void {
        // AVL-12: same-product overlap is *blocked*, not warned about. Two
        // departures of one trip on one boat are the same sailing sold twice.
        $product = conflictProduct();

        createManual($product);

        expect(fn () => createManual($product, ['local_time' => '10:00'], confirmed: true))
            ->toThrow(ValidationException::class);

        expect(Departure::query()->count())->toBe(1);
    });
})->group('fast');

it('does not warn when the gap is at least the turnaround', function (): void {
    conflictTenant(function (): void {
        // 09:00 + 4h = 13:00, plus a 60-minute turnaround: 14:00 is legal.
        $first = conflictProduct();
        $second = Product::factory()->create([
            'vessel_id' => $first->vessel_id,
            'max_pax' => 12,
            'duration_minutes' => 240,
        ]);

        createManual($first);

        $later = createManual($second, ['local_time' => '14:00']);

        expect($later->exists)->toBeTrue();
    });
})->group('fast');

it('warns one minute inside the turnaround', function (): void {
    conflictTenant(function (): void {
        $first = conflictProduct();
        $second = Product::factory()->create([
            'vessel_id' => $first->vessel_id,
            'max_pax' => 12,
            'duration_minutes' => 240,
        ]);

        createManual($first);

        expect(fn () => createManual($second, ['local_time' => '13:59']))
            ->toThrow(ValidationException::class);
    });
})->group('fast');

it('does not warn about a departure on another boat', function (): void {
    conflictTenant(function (): void {
        $first = conflictProduct();
        $other = Product::factory()->create([
            'vessel_id' => Vessel::factory()->create(['capacity_max' => 30])->getKey(),
            'max_pax' => 12,
            'duration_minutes' => 240,
        ]);

        createManual($first);

        expect(createManual($other)->exists)->toBeTrue();
    });
})->group('fast');

it('does not warn about a cancelled departure', function (): void {
    conflictTenant(function (): void {
        // A cancelled sailing occupies nothing. Warning about it would train
        // the operator to confirm past every warning.
        $first = conflictProduct();
        $second = Product::factory()->create([
            'vessel_id' => $first->vessel_id,
            'max_pax' => 12,
            'duration_minutes' => 240,
        ]);

        createManual($first)->forceFill([
            'status' => DepartureStatus::Cancelled,
        ])->saveQuietly();

        expect(createManual($second)->exists)->toBeTrue();
    });
})->group('fast');

it('refuses a departure on a whole-boat product', function (): void {
    conflictTenant(function (): void {
        $vessel = Vessel::factory()->create(['capacity_max' => 30]);

        createManual(Product::factory()->perVessel()->create(['vessel_id' => $vessel->getKey()]));
    });
})->throws(ValidationException::class)->group('fast');

it('refuses a local time that does not exist on the spring-forward date', function (): void {
    conflictTenant(function (): void {
        // ADR-0016, and the message has to say why *this* time will not do — a
        // manual departure is the remedy an operator is offered for a generated
        // one that was skipped.
        createManual(conflictProduct(), ['local_date' => '2026-03-29', 'local_time' => '03:30']);
    });
})->throws(ValidationException::class)->group('fast');

it('counts an empty departure as a warning but not as an occupation', function (): void {
    conflictTenant(function (): void {
        // AVL-10 and AVL-11 pull in opposite directions on purpose. An empty
        // departure is not an occupation — the boat is free — and it is still
        // the thing the operator must be told about, because it is what
        // silently stops being sellable.
        $product = conflictProduct();
        $vessel = $product->vessel;

        $departure = createManual($product);
        $window = DepartureConflictFinder::windowFor($product, '2026-07-04', '09:00');

        expect(VesselCalendar::occupationsFor($vessel, $window))->toHaveCount(0)
            ->and(VesselCalendar::isFree($vessel, $window))->toBeTrue()
            ->and(VesselCalendar::conflictingDepartures($vessel, $window))->toHaveCount(1);

        // One sold seat turns it into an occupation.
        $departure->forceFill(['seats_sold' => 1])->saveQuietly();

        expect(VesselCalendar::occupationsFor($vessel, $window))->toHaveCount(1)
            ->and(VesselCalendar::isFree($vessel, $window))->toBeFalse();
    });
})->group('fast');

it('never counts a departure as conflicting with itself', function (): void {
    conflictTenant(function (): void {
        // AVL-9. Without the exclusion, editing any departure would report a
        // conflict with the row being edited.
        $product = conflictProduct();
        $departure = createManual($product);
        $window = DepartureConflictFinder::windowFor($product, '2026-07-04', '09:00');

        expect(VesselCalendar::conflictingDepartures($product->vessel, $window, $departure))->toHaveCount(0);
    });
})->group('fast');
