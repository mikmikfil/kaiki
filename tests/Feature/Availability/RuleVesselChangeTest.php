<?php

declare(strict_types=1);

use App\Domain\Availability\Actions\GenerateDepartures;
use App\Domain\Availability\Actions\MoveDeparturesToRuleVessel;
use App\Domain\Availability\Support\DepartureReconciler;
use App\Domain\Availability\Support\WeekdayMask;
use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Filament\App\Resources\ScheduleRuleResource\Pages\EditScheduleRule;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Product;
use App\Models\ScheduleRule;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| A schedule's boat changes: its sailings follow, the sold ones are listed
|--------------------------------------------------------------------------
|
| Audit 2. Only the capacity followed a boat change, so the old boat looked
| busy and the new one free. ADR-0009's line holds: nothing somebody booked
| onto moves by itself.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-05-01 08:00:00');
    Queue::fake();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @return array{0: ScheduleRule, 1: Vessel, 2: Vessel} */
function vesselChangeRule(): array
{
    $big = Vessel::factory()->capacity(50)->create(['name' => 'Big Boat']);
    $small = Vessel::factory()->capacity(12)->create(['name' => 'Small Boat']);

    $product = Product::factory()->create([
        'vessel_id' => $big->getKey(),
        'max_pax' => 50,
        'duration_minutes' => 240,
    ]);

    $rule = ScheduleRule::factory()->create([
        'product_id' => $product->getKey(),
        'weekday_mask' => WeekdayMask::fromDays([1]),
        'start_time' => '19:00',
        'valid_from' => '2026-06-01',
        'valid_until' => '2026-06-30',
    ]);

    app(GenerateDepartures::class)($rule, Carbon::parse('2026-05-01', 'Europe/Athens'));

    return [$rule->refresh(), $big, $small];
}

function inVesselChangeTenant(callable $callback): mixed
{
    return Tenancy::forTenant(Tenant::factory()->create(['timezone' => 'Europe/Athens']), $callback);
}

it('moves unsold sailings to the new boat, with its capacity', function (): void {
    inVesselChangeTenant(function (): void {
        [$rule, $big, $small] = vesselChangeRule();

        expect(Departure::query()->where('vessel_id', $big->getKey())->count())->toBeGreaterThan(0);

        $rule->forceFill(['vessel_id' => $small->getKey()])->saveQuietly();
        app(GenerateDepartures::class)($rule->refresh(), Carbon::parse('2026-05-01', 'Europe/Athens'));

        expect(Departure::query()->where('vessel_id', $big->getKey())->count())->toBe(0)
            ->and(Departure::query()->where('vessel_id', $small->getKey())->pluck('capacity')->unique()->all())->toBe([12]);
    });
})->group('fast');

it('leaves a sold sailing on the old boat and lists it', function (): void {
    inVesselChangeTenant(function (): void {
        [$rule, $big, $small] = vesselChangeRule();

        $sold = Departure::query()->orderBy('starts_at_utc')->firstOrFail();
        $sold->forceFill(['seats_sold' => 8])->saveQuietly();

        $rule->forceFill(['vessel_id' => $small->getKey()])->saveQuietly();
        $result = app(MoveDeparturesToRuleVessel::class)($rule->refresh());

        expect($sold->refresh()->vessel_id)->toBe($big->getKey())
            ->and($sold->capacity)->toBe(50)
            ->and($result['kept']->pluck('id')->all())->toBe([$sold->getKey()])
            ->and($result['moved'])->toBeGreaterThan(0);

        $drift = collect(DepartureReconciler::forRule($rule->refresh(), Carbon::parse('2026-05-01', 'Europe/Athens')))
            ->where('kind', DepartureReconciler::VESSEL_DRIFT);

        expect($drift)->toHaveCount(1)
            ->and($drift->first()['detail'])->toBe('Big Boat → Small Boat');
    });
})->group('fast');

it('leaves an unsold sailing where it is when the new boat is taken at that hour', function (): void {
    inVesselChangeTenant(function (): void {
        [$rule, $big, $small] = vesselChangeRule();

        $first = Departure::query()->orderBy('starts_at_utc')->firstOrFail();

        // A private charter on the small boat, over the first sailing.
        Booking::factory()->perVessel()->create([
            'product_id' => $rule->product_id,
            'vessel_id' => $small->getKey(),
            'mode' => BookingMode::PerVessel,
            'status' => BookingStatus::Confirmed,
            'local_date' => $first->local_date->toDateString(),
            'local_time' => '19:00',
            'starts_at_utc' => $first->starts_at_utc,
            'ends_at_utc' => $first->ends_at_utc,
        ]);

        $rule->forceFill(['vessel_id' => $small->getKey()])->saveQuietly();
        app(MoveDeparturesToRuleVessel::class)($rule->refresh());

        expect($first->refresh()->vessel_id)->toBe($big->getKey())
            ->and(Departure::query()->whereKeyNot($first->getKey())->where('vessel_id', $big->getKey())->count())->toBe(0);
    });
})->group('fast');

it('follows the trip boat for a rule without its own', function (): void {
    inVesselChangeTenant(function (): void {
        [$rule, $big, $small] = vesselChangeRule();

        Product::query()->whereKey($rule->product_id)->update(['vessel_id' => $small->getKey()]);
        app(MoveDeparturesToRuleVessel::class)($rule->refresh());

        expect(Departure::query()->where('vessel_id', $big->getKey())->count())->toBe(0);
    });
})->group('fast');

it('tells the operator which sailings stayed on the old boat when they save', function (): void {
    $tenant = Tenant::factory()->create(['timezone' => 'Europe/Athens']);

    [$rule, $small, $sold] = Tenancy::forTenant($tenant, function (): array {
        [$rule, , $small] = vesselChangeRule();

        $sold = Departure::query()->orderBy('starts_at_utc')->firstOrFail();
        $sold->forceFill(['seats_sold' => 8])->saveQuietly();

        return [$rule, $small, $sold];
    });

    actingAs(OperatorUser::withRole(Role::Owner, $tenant));

    Tenancy::forTenant($tenant, function () use ($rule, $small, $sold): void {
        Livewire::test(EditScheduleRule::class, ['record' => $rule->getKey()])
            ->fillForm(['vessel_id' => $small->getKey()])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified(trans_choice('availability.vessel_move.kept', 1, ['count' => 1]));

        expect($sold->refresh()->vessel_id)->not->toBe($small->getKey())
            ->and(Departure::query()->whereKeyNot($sold->getKey())->where('vessel_id', '!=', $small->getKey())->count())->toBe(0);
    });
})->group('fast');
