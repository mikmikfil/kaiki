<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\SaveGuestDetails;
use App\Enums\GuestDetailsStatus;
use App\Models\AgeBand;
use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

use function Pest\Laravel\post;

use Tests\Support\Booking\GuestPageScenario;

/*
|--------------------------------------------------------------------------
| `/g/` applies checkout's rules (audit 2, 2026-09-25)
|--------------------------------------------------------------------------
|
| A date of birth has to fit the band the passenger was booked in — which is
| what keeps the adult escort rule true once the names are in — and a passport
| has to outlive the trip. `/g/` used to write whatever it was sent, mark the
| list complete and stop the reminders. A passenger already aboard keeps the
| identity they boarded under.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-06-20 09:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * One adult and one child who needs an adult, on a trip that asks for the list.
 *
 * @return array{0: Tenant, 1: Booking}
 */
function escortedFamily(): array
{
    [$tenant, $booking] = GuestPageScenario::booking(documentsRequired: true, pax: 2);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $adult = AgeBand::factory()->create(['product_id' => $booking->product_id, 'min_age' => 18]);
        $child = AgeBand::factory()->child()->create(['product_id' => $booking->product_id]);

        BookingGuest::query()->where('booking_id', $booking->getKey())->where('position', 1)
            ->update(['age_band_id' => $adult->getKey(), 'age_band_code' => 'adult']);
        BookingGuest::query()->where('booking_id', $booking->getKey())->where('position', 2)
            ->update(['age_band_id' => $child->getKey(), 'age_band_code' => 'child']);
    });

    return [$tenant, $booking->refresh()];
}

/** @return array<string, string|int> */
function passenger(int $position, string $name, string $born, string $expires = '2031-04-12'): array
{
    return [
        'position' => $position,
        'full_name' => $name,
        'date_of_birth' => $born,
        'nationality' => 'GR',
        'sex' => 'f',
        'document_type' => 'passport',
        'document_number' => 'AB123456' . $position,
        'document_expires_on' => $expires,
    ];
}

it('refuses a date of birth that does not fit the band, so a child is never listed without an adult', function (): void {
    [$tenant, $booking] = escortedFamily();
    $trip = $booking->local_date->copy();

    $valid = [
        passenger(1, 'Ελένη Νικολάου', $trip->copy()->subYears(40)->toDateString()),
        passenger(2, 'Νίκος Νικολάου', $trip->copy()->subYears(8)->toDateString()),
    ];

    post('/g/' . $booking->guest_details_token, ['guests' => $valid])->assertSessionHasNoErrors();

    expect(Tenancy::forTenant($tenant, fn (): GuestDetailsStatus => $booking->refresh()->guest_details_status))->toBe(GuestDetailsStatus::Complete);

    // The «adult» rewritten as a nine-year-old: two minors and no adult.
    $minor = $trip->copy()->subYears(9)->toDateString();

    post('/g/' . $booking->guest_details_token, ['guests' => [passenger(1, 'Ελένη Νικολάου', $minor)]])
        ->assertRedirect()
        ->assertSessionHasErrors(['guests.0.date_of_birth']);

    Tenancy::forTenant($tenant, function () use ($booking, $trip): void {
        $adult = BookingGuest::query()->where('booking_id', $booking->getKey())->where('position', 1)->sole();

        // Refused, that field only: the adult's date is the one stored before.
        expect($adult->date_of_birth?->toDateString())->toBe($trip->copy()->subYears(40)->toDateString())
            ->and($booking->refresh()->guest_details_status)->toBe(GuestDetailsStatus::Complete);
    });
})->group('fast');

it('refuses a passport that expires before the trip, and saves the rest of the row', function (): void {
    [$tenant, $booking] = escortedFamily();
    $trip = $booking->local_date->copy();

    post('/g/' . $booking->guest_details_token, ['guests' => [
        passenger(1, 'Ελένη Νικολάου', $trip->copy()->subYears(40)->toDateString(), expires: $trip->copy()->subMonth()->toDateString()),
    ]])->assertSessionHasErrors(['guests.0.document_expires_on']);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $adult = BookingGuest::query()->where('booking_id', $booking->getKey())->where('position', 1)->sole();

        expect($adult->full_name)->toBe('Ελένη Νικολάου')
            ->and($adult->document_expires_on)->toBeNull()
            ->and($booking->refresh()->guest_details_status)->toBe(GuestDetailsStatus::Pending);
    });
})->group('fast');

it('does not count a stored row that breaks the rules as complete', function (): void {
    [$tenant, $booking] = escortedFamily();
    $trip = $booking->local_date->copy();

    Tenancy::forTenant($tenant, function () use ($booking, $trip): void {
        // Written before this fix, straight past the form.
        foreach ([1 => 40, 2 => 8] as $position => $years) {
            BookingGuest::query()->where('booking_id', $booking->getKey())->where('position', $position)->sole()
                ->forceFill([
                    'full_name' => "Επιβάτης $position",
                    'date_of_birth' => $trip->copy()->subYears($years)->toDateString(),
                    'nationality' => 'GR',
                    'sex' => 'f',
                    'document_type' => 'passport',
                    'document_number' => 'X' . $position,
                    // The adult's passport ran out last month.
                    'document_expires_on' => $position === 1 ? $trip->copy()->subMonth()->toDateString() : '2031-01-01',
                ])->save();
        }

        expect(app(SaveGuestDetails::class)->syncStatus($booking))->toBe(GuestDetailsStatus::Pending);
    });
})->group('fast');

it('keeps the identity a passenger boarded under, and still lets a blank be filled in', function (): void {
    [$tenant, $booking] = escortedFamily();
    $trip = $booking->local_date->copy();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        BookingGuest::query()->where('booking_id', $booking->getKey())->where('position', 1)->sole()
            ->forceFill(['full_name' => 'Ελένη Νικολάου', 'checked_in_at' => now()])->save();
    });

    post('/g/' . $booking->guest_details_token, ['guests' => [
        passenger(1, 'Κάποιος Άλλος', $trip->copy()->subYears(40)->toDateString()),
    ]])->assertSessionHasNoErrors();

    Tenancy::forTenant($tenant, function () use ($booking, $trip): void {
        $adult = BookingGuest::query()->where('booking_id', $booking->getKey())->where('position', 1)->sole();

        expect($adult->full_name)->toBe('Ελένη Νικολάου')
            ->and($adult->date_of_birth?->toDateString())->toBe($trip->copy()->subYears(40)->toDateString());
    });
})->group('fast');
