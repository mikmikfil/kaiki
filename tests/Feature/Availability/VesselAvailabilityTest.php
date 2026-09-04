<?php

declare(strict_types=1);

use App\Data\Availability\AvailabilityRequestData;
use App\Data\Availability\VesselWindowData;
use App\Domain\Availability\Actions\CheckVesselAvailability;
use App\Domain\Availability\Contracts\VesselHoldSource;
use App\Domain\Availability\Support\OccupationCollector;
use App\Domain\Availability\Support\Window;
use App\Enums\AvailabilityRejection;
use App\Enums\BlockReason;
use App\Enums\ProductStatus;
use App\Enums\TenantStatus;
use App\Models\Departure;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Models\VesselBlock;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Whole-boat availability — spec AVL-30 to AVL-34
|--------------------------------------------------------------------------
|
| The heart of it is AVL-32: **a scheduled departure with no seats sold does not
| block a private window.** An operator who lists a shared cruise every Tuesday
| and is then offered a charter takes the charter — the empty departure was a
| hope, not a commitment.
|
| One seat sold reverses that entirely, and AVL-34 is blunt: *"There is no
| override in the guest flow."* A guest cannot buy their way past somebody who
| already booked.
|
| The auto-cancel of the taken-over departure is **not** here: AVL-32 puts it on
| confirmation, inside M2's booking transaction, because doing it at hold time
| would let an abandoned cart destroy a departure.
|
*/

function charterTenant(callable $callback): mixed
{
    Queue::fake();
    Carbon::setTestNow('2026-06-01 08:00:00');

    $result = Tenancy::forTenant(Tenant::factory()->create(['timezone' => 'Europe/Athens']), $callback);

    Carbon::setTestNow();

    return $result;
}

/** @param array<string, mixed> $overrides */
function charterFor(array $overrides = []): Product
{
    $vessel = Vessel::factory()->create(['capacity_max' => 12, 'turnaround_buffer_minutes' => 60]);

    return Product::factory()->perVessel()->create(array_merge([
        'vessel_id' => $vessel->getKey(),
        'status' => ProductStatus::Active,
        'duration_minutes' => 240,
        'default_start_time' => '10:00',
        'flexible_start' => false,
    ], $overrides))->load('vessel');
}

/** @return list<VesselWindowData> */
function charterCheck(Product $product, string $date = '2026-07-04', ?string $startTime = null, int $extraHours = 0): array
{
    return app(CheckVesselAvailability::class)(
        $product,
        AvailabilityRequestData::forRange($date, $date),
        $startTime,
        $extraHours,
    );
}

it('offers a free boat', function (): void {
    charterTenant(function (): void {
        $window = charterCheck(charterFor())[0];

        expect($window->available)->toBeTrue()
            ->and($window->startsAtLocal)->toBe('10:00:00')
            ->and($window->endsAtLocal)->toBe('14:00:00')
            ->and($window->rejection)->toBeNull();
    });
})->group('fast');

it('lets a charter take a window an empty departure sits in', function (): void {
    charterTenant(function (): void {
        // AVL-32's first half, and the thing that separates this service from
        // the per-seat one.
        $product = charterFor();
        $shared = Product::factory()->create([
            'vessel_id' => $product->vessel_id,
            'duration_minutes' => 240,
            'max_pax' => 10,
        ]);

        Departure::factory()->at('2026-07-04', '10:00', 240)->create([
            'product_id' => $shared->getKey(),
            'vessel_id' => $product->vessel_id,
        ]);

        expect(charterCheck($product)[0]->available)->toBeTrue();
    });
})->group('fast');

it('refuses when a conflicting departure has sold a seat', function (): void {
    charterTenant(function (): void {
        // AVL-34: no override in the guest flow. One booked guest outranks a
        // charter enquiry.
        $product = charterFor();
        $shared = Product::factory()->create([
            'vessel_id' => $product->vessel_id,
            'duration_minutes' => 240,
            'max_pax' => 10,
        ]);

        Departure::factory()->at('2026-07-04', '10:00', 240)->create([
            'product_id' => $shared->getKey(),
            'vessel_id' => $product->vessel_id,
        ])->forceFill(['seats_sold' => 1])->saveQuietly();

        $window = charterCheck($product)[0];

        expect($window->available)->toBeFalse()
            ->and($window->rejection)->toBe(AvailabilityRejection::VesselBusy);
    });
})->group('fast');

it('refuses when a held seat occupies the boat', function (): void {
    charterTenant(function (): void {
        // A hold is somebody at the checkout. AVL-3.2 counts
        // `seats_sold + seats_held > 0`, so a draft in progress occupies the
        // boat just as a paid seat does.
        $product = charterFor();
        $shared = Product::factory()->create([
            'vessel_id' => $product->vessel_id,
            'duration_minutes' => 240,
            'max_pax' => 10,
        ]);

        Departure::factory()->at('2026-07-04', '10:00', 240)->create([
            'product_id' => $shared->getKey(),
            'vessel_id' => $product->vessel_id,
        ])->forceFill(['seats_held' => 1])->saveQuietly();

        expect(charterCheck($product)[0]->available)->toBeFalse();
    });
})->group('fast');

it('refuses a block of any reason', function (BlockReason $reason): void {
    charterTenant(function () use ($reason): void {
        // AVL-3.1: *any* reason. Maintenance, an imported calendar event and a
        // private charter all mean the same thing to a boat.
        $product = charterFor();

        VesselBlock::factory()->between('2026-07-04', '11:00', '13:00')->create([
            'vessel_id' => $product->vessel_id,
            'reason' => $reason,
        ]);

        expect(charterCheck($product)[0]->rejection)->toBe(AvailabilityRejection::VesselBusy);
    });
})->with([
    [BlockReason::Maintenance],
    [BlockReason::Manual],
    [BlockReason::ExternalIcal],
    [BlockReason::PrivateBooking],
])->group('fast');

it('hides the boat while another guest holds it, and says so differently', function (): void {
    charterTenant(function (): void {
        // AVL-33. A hold lasts twenty minutes, so this is the one
        // unavailability a guest might reasonably wait out — reporting it as
        // "booked" would send them away from a boat about to be free.
        $product = charterFor();

        app()->tag([HoldsTheWholeAfternoon::class], OccupationCollector::HOLD_SOURCE_TAG);

        $window = charterCheck($product)[0];

        expect($window->available)->toBeFalse()
            ->and($window->rejection)->toBe(AvailabilityRejection::VesselHeld);
    });
})->group('fast');

it('frees the boat again once the hold expires', function (): void {
    charterTenant(function (): void {
        // The property a stored flag could not express: nothing is written and
        // nothing is deleted, so the answer simply changes when the clock does.
        $product = charterFor();

        app()->tag([ExpiredHold::class], OccupationCollector::HOLD_SOURCE_TAG);

        expect(charterCheck($product)[0]->available)->toBeTrue();
    });
})->group('fast');

it('reports no holds while nothing implements the contract', function (): void {
    charterTenant(function (): void {
        // The M1 state, pinned so that M2 registering a reader is a visible
        // change rather than a silent one.
        expect(charterCheck(charterFor())[0]->available)->toBeTrue();
    });
})->group('fast');

it('refuses an off-grid proposal without testing the calendar', function (): void {
    charterTenant(function (): void {
        $product = charterFor(['flexible_start' => true]);

        $window = charterCheck($product, startTime: '09:07')[0];

        expect($window->rejection)->toBe(AvailabilityRejection::OffGrid)
            // No window was built, so there is nothing to show a guest.
            ->and($window->startsAtLocal)->toBeNull();
    });
})->group('fast');

it('tests the extended window rather than the original', function (): void {
    charterTenant(function (): void {
        // The point of AVL-31's extension: a guest who adds two hours must be
        // refused by a conflict in the *added* hours, not only the first four.
        $product = charterFor(['flexible_start' => true]);

        VesselBlock::factory()->between('2026-07-04', '15:00', '16:00')->create([
            'vessel_id' => $product->vessel_id,
        ]);

        // 09:00 + 4h ends at 13:00, an hour clear of the block plus buffer.
        expect(charterCheck($product, startTime: '09:00')[0]->available)->toBeTrue()
            // 09:00 + 4h + 2h runs to 15:00, straight into it.
            ->and(charterCheck($product, startTime: '09:00', extraHours: 2)[0]->available)->toBeFalse();
    });
})->group('fast');

it('refuses every date while the operator is read-only', function (): void {
    Queue::fake();

    Tenancy::forTenant(Tenant::factory()->create([
        'timezone' => 'Europe/Athens',
        'status' => TenantStatus::ReadOnly,
    ]), function (): void {
        expect(charterCheck(charterFor())[0]->rejection)->toBe(AvailabilityRejection::TenantReadOnly);
    });
})->group('fast');

it('refuses a per-seat product outright', function (): void {
    charterTenant(function (): void {
        // Answering a seat product here would ignore every seat already sold.
        $vessel = Vessel::factory()->create(['capacity_max' => 12]);
        $product = Product::factory()->create(['vessel_id' => $vessel->getKey()])->load('vessel');

        expect(charterCheck($product)[0]->rejection)->toBe(AvailabilityRejection::ProductNotActive);
    });
})->group('fast');

it('returns one entry per requested date', function (): void {
    charterTenant(function (): void {
        $windows = app(CheckVesselAvailability::class)(
            charterFor(),
            AvailabilityRequestData::forRange('2026-07-04', '2026-07-06'),
        );

        expect($windows)->toHaveCount(3)
            ->and($windows[2]->localDate)->toBe('2026-07-06');
    });
})->group('fast');

/** A hold covering the whole of 4 July, for AVL-33. */
class HoldsTheWholeAfternoon implements VesselHoldSource
{
    /** @return list<Window> */
    public function holdWindows(Vessel $vessel, Window $range, Carbon $now): array
    {
        return [Window::of(
            Carbon::parse('2026-07-04 06:00:00', 'UTC'),
            Carbon::parse('2026-07-04 18:00:00', 'UTC'),
        )];
    }
}

/** A reader whose holds have all expired — the ordinary case a minute later. */
class ExpiredHold implements VesselHoldSource
{
    /** @return list<Window> */
    public function holdWindows(Vessel $vessel, Window $range, Carbon $now): array
    {
        return [];
    }
}
