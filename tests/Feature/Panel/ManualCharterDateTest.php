<?php

declare(strict_types=1);

use App\Enums\BookingMode;
use App\Enums\Role;
use App\Filament\App\Resources\BookingResource\Pages\CreateBooking;
use App\Models\Booking;
use App\Models\Product;
use App\Models\RatePlan;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\Support\Api\BookingApiScenario;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| A phone charter lands on the day the operator typed (audit, 2026-09-25)
|--------------------------------------------------------------------------
|
| «Νέα κράτηση» had no date for a charter — charters have no departures — so
| every one was made for `Carbon::today()`, the server's UTC date: today, not
| 12 October, and yesterday between midnight and 03:00 in Athens. The form now
| asks the day and the start (the trip's own by default), on the operator's
| calendar, and a per-seat booking must name its sailing.
|
*/

beforeEach(function (): void {
    // 01:30 on 26 September in Athens; still the 25th in UTC.
    Carbon::setTestNow('2026-09-25 22:30:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @return array<string, mixed> */
function phoneCharterFixture(): array
{
    $fixture = BookingApiScenario::bookable(mode: BookingMode::PerVessel);

    $fixture['product'] = Tenancy::forTenant($fixture['tenant'], function () use ($fixture): Product {
        $fixture['tenant']->forceFill(['timezone' => 'Europe/Athens'])->save();

        $fixture['product']->forceFill(['default_start_time' => '09:00', 'duration_minutes' => 480])->save();

        RatePlan::query()->where('product_id', $fixture['product']->getKey())->update(['vessel_price_cents' => 50000]);

        $fixture['departure']->delete();

        return $fixture['product']->fresh(['ageBands']);
    });

    actingAs(OperatorUser::withRole(Role::Owner, $fixture['tenant']));

    return $fixture;
}

it('books a phone charter on the day and hour typed, in Athens time', function (): void {
    $fixture = phoneCharterFixture();

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        Livewire::test(CreateBooking::class)
            ->fillForm(['product_id' => $fixture['product']->getKey()])
            // The trip's own start, offered and left to change.
            ->assertFormSet(['start_time' => '09:00'])
            ->fillForm([
                'date' => '2026-10-12',
                'start_time' => '10:30',
                'pax' => [['code' => 'adult', 'qty' => 4]],
                'guest_name' => 'Γιώργος Παπαδάκης',
                'guest_email' => 'giorgos@example.gr',
                'payment' => 'on_the_day',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $booking = Booking::query()->sole();

        expect($booking->local_date->toDateString())->toBe('2026-10-12')
            ->and(substr((string) $booking->local_time, 0, 5))->toBe('10:30')
            ->and($booking->starts_at_utc->toDateTimeString())->toBe('2026-10-12 07:30:00');
    });
})->group('fast');

it('offers today on the operator calendar, not the server one', function (): void {
    $fixture = phoneCharterFixture();

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        Livewire::test(CreateBooking::class)
            ->assertFormSet(['date' => static fn (mixed $date): bool => str_starts_with((string) $date, '2026-09-26')])
            ->fillForm([
                'product_id' => $fixture['product']->getKey(),
                'pax' => [['code' => 'adult', 'qty' => 2]],
                'guest_name' => 'Γιώργος Παπαδάκης',
                'guest_email' => 'giorgos@example.gr',
                'payment' => 'on_the_day',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $booking = Booking::query()->sole();

        expect($booking->local_date->toDateString())->toBe('2026-09-26')
            ->and(substr((string) $booking->local_time, 0, 5))->toBe('09:00');
    });
})->group('fast');

it('asks for the date of a charter, and for the sailing of a per-seat trip', function (): void {
    $fixture = phoneCharterFixture();

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        Livewire::test(CreateBooking::class)
            ->fillForm([
                'product_id' => $fixture['product']->getKey(),
                'date' => null,
                'pax' => [['code' => 'adult', 'qty' => 2]],
                'guest_name' => 'Γιώργος Παπαδάκης',
                'guest_email' => 'giorgos@example.gr',
                'payment' => 'on_the_day',
            ])
            ->call('create')
            ->assertHasFormErrors(['date' => 'required']);

        $perSeat = Product::factory()->create(['vessel_id' => $fixture['product']->vessel_id, 'mode' => BookingMode::PerSeat]);

        Livewire::test(CreateBooking::class)
            ->fillForm([
                'product_id' => $perSeat->getKey(),
                'pax' => [['code' => 'adult', 'qty' => 2]],
                'guest_name' => 'Γιώργος Παπαδάκης',
                'guest_email' => 'giorgos@example.gr',
                'payment' => 'on_the_day',
            ])
            ->call('create')
            ->assertHasFormErrors(['departure_id' => 'required']);

        expect(Booking::query()->count())->toBe(0);
    });
})->group('fast');
