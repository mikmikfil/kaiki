<?php

declare(strict_types=1);

use App\Domain\Availability\Actions\UpdateDeparture;
use App\Domain\Booking\Actions\CreateManualBooking;
use App\Domain\Booking\Data\BookingDraftData;
use App\Enums\AvailabilityRejection;
use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Enums\PaymentGatewayName;
use App\Enums\Role;
use App\Exceptions\CapacityLoweringRefused;
use App\Exceptions\HoldRefused;
use App\Exceptions\PartyRefused;
use App\Filament\App\Resources\BookingResource\Pages\CreateBooking;
use App\Models\AgeBand;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

use Tests\Support\Api\BookingApiScenario;
use Tests\Support\Api\CatalogRequest;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| Everyone aboard, at every door — AVL-25 and CAT-5 (2026-09-25)
|--------------------------------------------------------------------------
|
| The certificate (`vessels.capacity_max`) counts people, infants included; a
| departure's capacity counts seats. Until this the booking doors asked only
| the second: HoldSeats ran the legal sum on the override path alone, a charter
| was never measured at all, a departure could be raised past its boat, and a
| boat could be re-certified below the people already booked. The trip's own
| per-booking bounds were asked by the calendar and nobody else.
|
*/

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * A per-seat or charter trip on a boat licensed for `$certificate`, with an
 * adult and an infant band (the infant takes no seat).
 *
 * @return array{tenant: Tenant, product: Product, departure: Departure, band: AgeBand, infant: AgeBand, key: string}
 */
function headcountFixture(BookingMode $mode = BookingMode::PerSeat, int $seats = 12, int $certificate = 12): array
{
    $fixture = BookingApiScenario::bookable(mode: $mode, capacity: $seats);

    return Tenancy::forTenant($fixture['tenant'], function () use ($fixture, $mode, $certificate): array {
        Vessel::query()->whereKey($fixture['product']->vessel_id)->update(['capacity_max' => $certificate]);

        $fixture['infant'] = AgeBand::factory()->infant()->create(['product_id' => $fixture['product']->getKey()]);

        if ($mode === BookingMode::PerVessel) {
            $fixture['product']->forceFill(['default_start_time' => '09:00', 'duration_minutes' => 240])->save();
            RatePlan::query()->where('product_id', $fixture['product']->getKey())->update(['vessel_price_cents' => 50000]);
            $fixture['departure']->delete();
        }

        $fixture['product'] = $fixture['product']->fresh(['ageBands', 'vessel']);

        return $fixture;
    });
}

/**
 * `POST /bookings` with this party.
 *
 * @param  array<string, mixed>  $fixture
 * @param  array<string, int>  $party  `adult` / `infant` => how many
 * @return TestResponse<JsonResponse>
 */
function postHeadcount(array $fixture, array $party): TestResponse
{
    $pax = [];

    foreach ($party as $code => $qty) {
        $pax[] = ['age_band_uuid' => $code === 'infant' ? $fixture['infant']->uuid : $fixture['band']->uuid, 'qty' => $qty];
    }

    $overrides = ['pax' => $pax];

    if ($fixture['product']->mode === BookingMode::PerVessel) {
        $overrides['departure_uuid'] = null;
        $overrides['window'] = ['local_date' => Carbon::now()->addDays(30)->toDateString(), 'local_time' => '09:00'];
    }

    return postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band'], $overrides),
        ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => BookingApiScenario::idempotencyKey()],
    );
}

/**
 * The operator's draft for this party on the fixture's sailing.
 *
 * @param  array<string, mixed>  $fixture
 * @param  array<string, int>  $party
 */
function headcountDraft(array $fixture, array $party): BookingDraftData
{
    return new BookingDraftData(
        product: $fixture['product'],
        date: $fixture['departure']->local_date->copy(),
        guestName: 'Γιώργος Νικολάου',
        guestEmail: 'giorgos@example.gr',
        paxByCode: $party,
        startTime: (string) $fixture['departure']->local_time,
    );
}

/**
 * Ten adults, paid in cash, already aboard the fixture's sailing.
 *
 * @param  array<string, mixed>  $fixture
 */
function tenAboard(array $fixture): void
{
    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        app(CreateManualBooking::class)(headcountDraft($fixture, ['adult' => 10]), paidBy: PaymentGatewayName::Cash);
    });
}

it('refuses infants that push a sailing over the certificate, though the seats are free', function (): void {
    $fixture = headcountFixture();
    tenAboard($fixture);

    // Two seats left and two adults asking: the seat check passes. Two adults
    // and eight infants are twenty people on a boat licensed for twelve.
    $response = postHeadcount($fixture, ['adult' => 2, 'infant' => 8]);

    $response->assertStatus(422)->assertJsonPath('error.code', 'legal_capacity');
    expect($response->json('error.message_el'))->toBe(__('booking.hold.legal_capacity', [], 'el'))
        // Nobody is holding anything: a refused hold leaves no seat taken.
        ->and(Booking::query()->where('status', BookingStatus::Draft->value)->whereNotNull('hold_expires_at')->count())->toBe(0);

    // The same two adults with no infants still fit.
    postHeadcount($fixture, ['adult' => 2])->assertCreated();
})->group('fast');

it('refuses the same infants at the quay and on the phone, without an override', function (): void {
    $fixture = headcountFixture();
    tenAboard($fixture);

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        expect(fn () => app(CreateManualBooking::class)(headcountDraft($fixture, ['adult' => 2, 'infant' => 3])))
            ->toThrow(HoldRefused::class, __('booking.hold.legal_capacity'));
    });
})->group('fast');

it('counts people already holding seats, not only people who have paid', function (): void {
    $fixture = headcountFixture();

    // A guest at the gateway: two adults and six infants, held, not paid.
    postHeadcount($fixture, ['adult' => 2, 'infant' => 6])->assertCreated();

    // Eight aboard; five more is thirteen on a boat for twelve.
    postHeadcount($fixture, ['adult' => 5])->assertStatus(422)->assertJsonPath('error.code', 'legal_capacity');
})->group('fast');

it('refuses a charter of forty on a boat licensed for twelve', function (): void {
    Carbon::setTestNow('2026-09-25 10:00:00');
    $fixture = headcountFixture(BookingMode::PerVessel);

    // The trip's own maximum (twelve, the certificate) answers first.
    postHeadcount($fixture, ['adult' => 40])->assertStatus(422)->assertJsonPath('error.code', 'too_many_pax');

    // With no maximum on the trip, the certificate still refuses.
    Tenancy::forTenant($fixture['tenant'], fn () => $fixture['product']->forceFill(['max_pax' => 0])->save());
    postHeadcount($fixture, ['adult' => 40])->assertStatus(422)->assertJsonPath('error.code', 'legal_capacity_exceeded');

    expect(Booking::query()->count())->toBe(0);
})->group('fast');

it('refuses a charter whose infants take it over the certificate', function (): void {
    Carbon::setTestNow('2026-09-25 10:00:00');
    $fixture = headcountFixture(BookingMode::PerVessel);

    // Ten seats is inside the trip's maximum; ten and five infants is fifteen people.
    $response = postHeadcount($fixture, ['adult' => 10, 'infant' => 5]);

    $response->assertStatus(422)->assertJsonPath('error.code', 'legal_capacity_exceeded');
    expect($response->json('error.message_el'))->toBe(AvailabilityRejection::LegalCapacityExceeded->labelIn('el'));

    postHeadcount($fixture, ['adult' => 10, 'infant' => 2])->assertCreated();
})->group('fast');

it('lets the panel override lift the commercial limit but never the certificate', function (): void {
    // Two seats on sale on a boat licensed for twelve.
    $fixture = headcountFixture(seats: 2);

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        $override = 'Οικογένεια, το σκάφος χωράει';

        // Three adults: past the departure's two seats and the trip's maximum
        // of two, both commercial — the override lifts them.
        $booking = app(CreateManualBooking::class)(headcountDraft($fixture, ['adult' => 3]), capacityOverrideReason: $override);
        expect($booking->pax_capacity_total)->toBe(3);

        // Three adults and ten infants: thirteen people. No override reaches it.
        expect(fn () => app(CreateManualBooking::class)(
            headcountDraft($fixture, ['adult' => 3, 'infant' => 10]),
            capacityOverrideReason: $override,
        ))->toThrow(HoldRefused::class, __('booking.hold.legal_capacity'));
    });
})->group('fast');

it('refuses a charter past the certificate in the panel, override or not', function (): void {
    Carbon::setTestNow('2026-09-25 10:00:00');
    $fixture = headcountFixture(BookingMode::PerVessel);

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        $draft = new BookingDraftData(
            product: $fixture['product'],
            date: Carbon::now()->addDays(30),
            guestName: 'Γιώργος Νικολάου',
            guestEmail: 'giorgos@example.gr',
            paxByCode: ['adult' => 12, 'infant' => 3],
            startTime: '09:00',
        );

        try {
            app(CreateManualBooking::class)($draft, capacityOverrideReason: 'Θα χωρέσουν');

            throw new RuntimeException('A charter of fifteen on a boat for twelve should have been refused.');
        } catch (PartyRefused $refused) {
            expect($refused->rejection)->toBe(AvailabilityRejection::LegalCapacityExceeded);
        }
    });
})->group('fast');

it('refuses a party below the trip minimum at the API, in both languages', function (): void {
    $fixture = headcountFixture();
    Tenancy::forTenant($fixture['tenant'], fn () => $fixture['product']->forceFill(['min_booking_pax' => 4])->save());

    $response = postHeadcount($fixture, ['adult' => 2]);

    $response->assertStatus(422)->assertJsonPath('error.code', 'too_few_pax');
    expect($response->json('error.message'))->toBe('This trip is booked for at least 4 people.')
        ->and($response->json('error.message_el'))->toBe('Η εκδρομή κλείνεται για τουλάχιστον 4 άτομα.');

    postHeadcount($fixture, ['adult' => 4])->assertCreated();
})->group('fast');

it('refuses a party above the trip maximum at the API, and the quote says the same', function (): void {
    $fixture = headcountFixture();
    Tenancy::forTenant($fixture['tenant'], fn () => $fixture['product']->forceFill(['max_pax' => 6])->save());

    postHeadcount($fixture, ['adult' => 7])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'too_many_pax')
        ->assertJsonPath('error.message', 'This trip takes at most 6 people per booking.');

    // One answer: the quote refuses the same party in the same words.
    postJson(CatalogRequest::url('/price-quote'), [
        'product_uuid' => $fixture['product']->uuid,
        'departure_uuid' => $fixture['departure']->uuid,
        'pax' => [['age_band_uuid' => $fixture['band']->uuid, 'qty' => 7]],
    ], ['Authorization' => "Bearer {$fixture['key']}"])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'too_many_pax')
        ->assertJsonPath('error.message', 'This trip takes at most 6 people per booking.');
})->group('fast');

it('refuses a party outside the trip bounds in the panel', function (): void {
    $fixture = headcountFixture();
    $product = $fixture['product'];

    Tenancy::forTenant($fixture['tenant'], fn () => $product->forceFill(['min_booking_pax' => 4, 'max_pax' => 6])->save());

    actingAs(OperatorUser::withRole(Role::Owner, $fixture['tenant']));

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture, $product): void {
        foreach ([2 => AvailabilityRejection::TooFewPax, 8 => AvailabilityRejection::TooManyPax] as $qty => $rejection) {
            Livewire::test(CreateBooking::class)
                ->fillForm([
                    'product_id' => $product->getKey(),
                    'departure_id' => $fixture['departure']->getKey(),
                    'pax' => [['code' => 'adult', 'qty' => $qty]],
                    'guest_name' => 'Γιώργος Παπαδάκης',
                    'guest_email' => 'giorgos@example.gr',
                    'payment' => 'on_the_day',
                ])
                ->call('create')
                ->assertNotified($rejection->sentenceIn(app()->getLocale(), $product->fresh()));
        }

        expect(Booking::query()->count())->toBe(0);

        // The override lifts the maximum, a commercial number; never the minimum.
        $draft = headcountDraft(['product' => $product->fresh(['ageBands', 'vessel'])] + $fixture, ['adult' => 8]);
        expect(app(CreateManualBooking::class)($draft, capacityOverrideReason: 'Μία παρέα')->pax_capacity_total)->toBe(8);

        $few = headcountDraft(['product' => $product->fresh(['ageBands', 'vessel'])] + $fixture, ['adult' => 2]);
        expect(fn () => app(CreateManualBooking::class)($few, capacityOverrideReason: 'Μία παρέα'))
            ->toThrow(PartyRefused::class);
    });
})->group('fast');

it('refuses to raise a departure above the boat certificate', function (): void {
    $fixture = headcountFixture();

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        try {
            app(UpdateDeparture::class)($fixture['departure']->fresh(), ['capacity' => 15]);

            throw new RuntimeException('A departure of fifteen on a boat for twelve should have been refused.');
        } catch (ValidationException $refused) {
            expect($refused->errors()['capacity'][0])->toContain('12');
        }

        expect($fixture['departure']->fresh()?->capacity)->toBe(12);

        // Up to the certificate is ordinary work.
        expect(app(UpdateDeparture::class)($fixture['departure']->fresh(), ['capacity' => 12])->capacity)->toBe(12);
    });
})->group('fast');

it('lets a departure left above the certificate come down in steps', function (): void {
    $fixture = headcountFixture();

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        $fixture['departure']->forceFill(['capacity' => 20])->save();

        expect(app(UpdateDeparture::class)($fixture['departure']->fresh(), ['capacity' => 15])->capacity)->toBe(15);
    });
})->group('fast');

it('refuses to lower the certificate below the people already booked', function (): void {
    $fixture = headcountFixture(certificate: 20);
    tenAboard($fixture);

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        // Ten adults and four infants on the sailing: fourteen people.
        app(CreateManualBooking::class)(headcountDraft($fixture, ['adult' => 1, 'infant' => 3]), paidBy: PaymentGatewayName::Cash);

        $vessel = Vessel::query()->findOrFail($fixture['product']->vessel_id);

        try {
            $vessel->update(['capacity_max' => 12]);

            throw new RuntimeException('Lowering the certificate below fourteen booked should have been refused.');
        } catch (CapacityLoweringRefused $refused) {
            expect($refused->claims)->toHaveCount(1)
                ->and($refused->claims[0]->pax)->toBe(14)
                ->and($refused->getMessage())->toContain('(14)');
        }

        expect($vessel->fresh()?->capacity_max)->toBe(20);

        // Down to the headcount itself is allowed: fourteen on a boat for fourteen.
        $vessel->refresh()->update(['capacity_max' => 14]);
        expect($vessel->fresh()?->capacity_max)->toBe(14);
    });
})->group('fast');
