<?php

declare(strict_types=1);

use App\Domain\Availability\Actions\CreateVesselBlock;
use App\Enums\BlockReason;
use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Filament\App\Resources\VesselBlockResource\Pages\CreateVesselBlock as CreateVesselBlockPage;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Models\VesselBlock;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| A block never lands on a sold booking in silence (audit, 2026-09-25)
|--------------------------------------------------------------------------
|
| The create page warned only about per-seat sailings with seats. A block over
| a confirmed charter — no departure, so nothing to count — said nothing, and
| the guest kept a ticket for a boat in the yard. Now the block is refused
| until the operator confirms, and the refusal names the bookings.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-25 10:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * A boat with a confirmed 10:00–16:00 charter on 5 October (Athens).
 *
 * @return array{vessel: Vessel, charter: Booking}
 */
function charteredBoat(): array
{
    $vessel = Vessel::factory()->create(['capacity_max' => 20]);
    $product = Product::factory()->perVessel()->create(['vessel_id' => $vessel->getKey()]);

    $charter = Booking::factory()->create([
        'product_id' => $product->getKey(),
        'vessel_id' => $vessel->getKey(),
        'departure_id' => null,
        'mode' => BookingMode::PerVessel,
        'status' => BookingStatus::Confirmed,
        'local_date' => '2026-10-05',
        'local_time' => '10:00',
        'starts_at_utc' => Carbon::parse('2026-10-05 07:00:00'),
        'ends_at_utc' => Carbon::parse('2026-10-05 13:00:00'),
    ]);

    return ['vessel' => $vessel, 'charter' => $charter];
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function maintenanceOn(string $date, array $extra = []): array
{
    return array_merge([
        'reason' => BlockReason::Maintenance,
        'is_all_day' => true,
        'local_date' => $date,
    ], $extra);
}

function athensTenant(): Tenant
{
    return Tenant::factory()->create(['timezone' => 'Europe/Athens']);
}

it('refuses a block over a confirmed charter and names it', function (): void {
    Tenancy::forTenant(athensTenant(), function (): void {
        ['vessel' => $vessel, 'charter' => $charter] = charteredBoat();

        $refused = null;

        try {
            app(CreateVesselBlock::class)($vessel, maintenanceOn('2026-10-05'));
        } catch (ValidationException $exception) {
            $refused = $exception;
        }

        expect($refused)->toBeInstanceOf(ValidationException::class)
            ->and($refused?->errors())->toHaveKey('confirm_overlap')
            ->and($refused?->errors()['confirm_overlap'][0] ?? '')->toContain($charter->reference);

        expect(VesselBlock::query()->count())->toBe(0);
    });
})->group('fast');

it('writes it once the operator confirms, and cancels nothing', function (): void {
    Tenancy::forTenant(athensTenant(), function (): void {
        ['vessel' => $vessel, 'charter' => $charter] = charteredBoat();

        app(CreateVesselBlock::class)($vessel, maintenanceOn('2026-10-05', ['confirm_overlap' => true]));

        expect(VesselBlock::query()->count())->toBe(1)
            ->and($charter->fresh()?->status)->toBe(BookingStatus::Confirmed);
    });
})->group('fast');

it('names a per-seat booking under the block too, and lets a free day through', function (): void {
    Tenancy::forTenant(athensTenant(), function (): void {
        $vessel = Vessel::factory()->create(['capacity_max' => 20]);
        $product = Product::factory()->create(['vessel_id' => $vessel->getKey(), 'mode' => BookingMode::PerSeat]);
        $departure = Departure::factory()->for($vessel)->at('2026-10-06', '11:00', 120)->withSeats(2)->create(['product_id' => $product->getKey()]);

        $seat = Booking::factory()->create([
            'product_id' => $product->getKey(),
            'vessel_id' => $vessel->getKey(),
            'departure_id' => $departure->getKey(),
            'mode' => BookingMode::PerSeat,
            'status' => BookingStatus::Confirmed,
            'local_date' => '2026-10-06',
            'local_time' => '11:00',
            'starts_at_utc' => $departure->starts_at_utc,
            'ends_at_utc' => $departure->ends_at_utc,
        ]);

        expect(fn () => app(CreateVesselBlock::class)($vessel, maintenanceOn('2026-10-06')))
            ->toThrow(ValidationException::class, $seat->reference);

        // A day with nobody booked needs no confirmation.
        app(CreateVesselBlock::class)($vessel, maintenanceOn('2026-10-07'));

        expect(VesselBlock::query()->count())->toBe(1);
    });
})->group('fast');

it('does not count the booking the block is for', function (): void {
    Tenancy::forTenant(athensTenant(), function (): void {
        ['vessel' => $vessel, 'charter' => $charter] = charteredBoat();

        app(CreateVesselBlock::class)($vessel, maintenanceOn('2026-10-05', [
            'reason' => BlockReason::PrivateBooking,
            'booking_id' => $charter->getKey(),
        ]));

        expect(VesselBlock::query()->count())->toBe(1);
    });
})->group('fast');

it('asks on the panel, naming the charter, and saves once confirmed', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $tenant = Tenant::query()->findOrFail($owner->tenant_id);
    $tenant->forceFill(['timezone' => 'Europe/Athens'])->save();
    tenancy()->initialize($tenant);

    ['vessel' => $vessel, 'charter' => $charter] = charteredBoat();

    $page = Livewire::actingAs($owner)->test(CreateVesselBlockPage::class)
        ->fillForm([
            'vessel_id' => $vessel->getKey(),
            'reason' => BlockReason::Maintenance->value,
            'is_all_day' => true,
            'local_date' => '2026-10-05',
        ])
        ->call('create')
        ->assertHasFormErrors(['confirm_overlap']);

    expect(VesselBlock::query()->count())->toBe(0)
        ->and(implode(' ', $page->errors()->get('data.confirm_overlap')))->toContain($charter->reference);

    $page->fillForm(['confirm_overlap' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(VesselBlock::query()->count())->toBe(1);
})->group('fast');
