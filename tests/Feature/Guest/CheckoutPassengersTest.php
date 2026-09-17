<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\SaveGuestDetails;
use App\Domain\Booking\Support\ManifestRows;
use App\Domain\Catalog\Actions\SaveAgeBands;
use App\Enums\BookingStatus;
use App\Enums\GuestDetailsStatus;
use App\Enums\GuestDocumentType;
use App\Models\AgeBand;
use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\Tenant;
use App\Support\Countries;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

use Tests\Support\Booking\GuestPageScenario;
use Tests\Support\Payments\WebhookScenario;

/*
|--------------------------------------------------------------------------
| Passenger details inside checkout (product owner, 2026-09-17)
|--------------------------------------------------------------------------
|
| Name, nationality and date of birth for everybody; a passport (number and
| expiry) or an identity card (number) unless the band is «Χωρίς έγγραφο»; a
| date of birth that fits the band on the day of the trip. And the rows exist
| for bookings nobody imported — before this, none did.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    WebhookScenario::fakeGatewayResponses();
    Carbon::setTestNow('2026-06-20 09:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * A draft for one adult and one infant, on a trip that asks for passengers,
 * with no guest rows yet — the way a widget booking arrives.
 *
 * @return array{0: Tenant, 1: Booking}
 */
function passengerDraft(): array
{
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 12000, documentsRequired: true);

    Tenancy::forTenant($tenant, static function () use ($booking): void {
        $adult = AgeBand::factory()->create(['product_id' => $booking->product_id]);
        $infant = AgeBand::factory()->infant()->create(['product_id' => $booking->product_id]);

        BookingGuest::query()->where('booking_id', $booking->getKey())->delete();

        $booking->forceFill([
            'status' => BookingStatus::Draft,
            'paid_cents' => 0,
            'hold_expires_at' => now()->addMinutes(15),
            'local_date' => '2026-07-10',
            'pax_total' => 2,
            'pax_breakdown' => [
                ['code' => 'adult', 'age_band_uuid' => $adult->uuid, 'qty' => 1, 'label' => ['el' => 'Ενήλικας', 'en' => 'Adult']],
                ['code' => 'infant', 'age_band_uuid' => $infant->uuid, 'qty' => 1, 'label' => ['el' => 'Βρέφος', 'en' => 'Infant']],
            ],
        ])->save();
    });

    return [$tenant, $booking->refresh()];
}

/**
 * @param  array<string, string>  $adult
 * @param  array<string, string>  $infant
 * @return array<string, mixed>
 */
function passengerPost(array $adult = [], array $infant = []): array
{
    return [
        'guest_name' => 'Μαρία Παπαδοπούλου',
        'guest_email' => 'maria@example.com',
        'terms' => '1',
        'guests' => [
            [
                'position' => 1,
                'full_name' => 'Μαρία Παπαδοπούλου',
                'nationality' => 'GR',
                'date_of_birth' => '1990-05-01',
                'document_type' => 'passport',
                'document_number' => 'AB1234567',
                'document_expires_on' => '2030-01-01',
                ...$adult,
            ],
            [
                'position' => 2,
                'full_name' => 'Νίκος Παπαδόπουλος',
                'nationality' => 'GR',
                'date_of_birth' => '2025-03-01',
                ...$infant,
            ],
        ],
    ];
}

it('creates one row per person from the breakdown, once', function (): void {
    [$tenant, $booking] = passengerDraft();

    Tenancy::forTenant($tenant, static function () use ($booking): void {
        ManifestRows::ensure($booking);
        ManifestRows::ensure($booking);

        $rows = BookingGuest::query()->where('booking_id', $booking->getKey())->orderBy('position')->get();

        expect($rows)->toHaveCount(2)
            ->and($rows[0]->age_band_code)->toBe('adult')
            ->and($rows[0]->is_lead)->toBeTrue()
            ->and($rows[0]->full_name)->toBe($booking->guest_name)
            ->and($rows[1]->age_band_code)->toBe('infant')
            ->and($rows[1]->isDocumentFree())->toBeTrue();
    });
})->group('fast');

it('treats a band ending at two as document-free unless the operator says otherwise', function (): void {
    $infant = new AgeBand(['min_age' => 0, 'max_age' => 2]);
    $child = new AgeBand(['min_age' => 3, 'max_age' => 11]);
    $overridden = new AgeBand(['min_age' => 0, 'max_age' => 2, 'no_document' => false]);

    expect($infant->isDocumentFree())->toBeTrue()
        ->and($child->isDocumentFree())->toBeFalse()
        ->and($overridden->isDocumentFree())->toBeFalse();
})->group('fast');

it('asks every passenger the right questions at checkout', function (): void {
    [, $booking] = passengerDraft();

    get('/c/' . $booking->manage_token)
        ->assertOk()
        ->assertSee('name="guests[0][position]" value="1"', escape: false)
        ->assertSee('name="guests[1][position]" value="2"', escape: false)
        ->assertSee('name="guests[0][nationality]"', escape: false)
        ->assertSee('name="guests[0][document_type]"', escape: false)
        ->assertSee('name="guests[0][document_expires_on]"', escape: false)
        // The infant: no document fields, and a sentence saying so.
        ->assertDontSee('name="guests[1][document_number]"', escape: false)
        ->assertSee(__('guest.checkout.no_document', [], $booking->locale), escape: false)
        // Passport or identity card, and nothing else.
        ->assertSee('value="passport"', escape: false)
        ->assertSee('value="id_card"', escape: false)
        ->assertDontSee('value="other"', escape: false);
})->group('fast');

it('writes the passengers onto the manifest before sending the guest to pay', function (): void {
    [$tenant, $booking] = passengerDraft();

    post('/c/' . $booking->manage_token, passengerPost())
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    Tenancy::forTenant($tenant, static function () use ($booking): void {
        $rows = BookingGuest::query()->where('booking_id', $booking->getKey())->orderBy('position')->get();

        expect($rows[0]->nationality)->toBe('GR')
            ->and($rows[0]->document_type)->toBe(GuestDocumentType::Passport)
            ->and($rows[0]->document_number)->toBe('AB1234567')
            ->and($rows[0]->document_expires_on?->toDateString())->toBe('2030-01-01')
            ->and($rows[1]->full_name)->toBe('Νίκος Παπαδόπουλος')
            ->and($rows[1]->document_number)->toBeNull()
            ->and($booking->refresh()->guest_details_status)->toBe(GuestDetailsStatus::Complete);
    });
})->group('fast');

it('asks a passport for its expiry and an identity card for its number only', function (): void {
    [, $booking] = passengerDraft();

    post('/c/' . $booking->manage_token, passengerPost(adult: ['document_expires_on' => '']))
        ->assertSessionHasErrors('guests.0.document_expires_on');

    post('/c/' . $booking->manage_token, passengerPost(adult: ['document_expires_on' => '2026-07-01']))
        ->assertSessionHasErrors('guests.0.document_expires_on');

    post('/c/' . $booking->manage_token, passengerPost(adult: ['document_type' => 'id_card', 'document_expires_on' => '']))
        ->assertSessionHasNoErrors();
})->group('fast');

it('refuses a missing document, an old document type and a date of birth outside the band', function (): void {
    [, $booking] = passengerDraft();

    post('/c/' . $booking->manage_token, passengerPost(adult: ['document_type' => '', 'document_number' => '']))
        ->assertSessionHasErrors(['guests.0.document_type', 'guests.0.document_number']);

    post('/c/' . $booking->manage_token, passengerPost(adult: ['document_type' => 'other']))
        ->assertSessionHasErrors('guests.0.document_type');

    // An «infant» who is seven on the day of the trip.
    post('/c/' . $booking->manage_token, passengerPost(infant: ['date_of_birth' => '2019-01-01']))
        ->assertSessionHasErrors('guests.1.date_of_birth');

    // An «adult» born last year.
    post('/c/' . $booking->manage_token, passengerPost(adult: ['date_of_birth' => '2025-01-01']))
        ->assertSessionHasErrors('guests.0.date_of_birth');

    post('/c/' . $booking->manage_token, passengerPost(adult: ['nationality' => '']))
        ->assertSessionHasErrors('guests.0.nationality');

    // A country typed out, not chosen: the column holds a two-letter code, and
    // MySQL refused the longer value with a 500 before this was a list.
    post('/c/' . $booking->manage_token, passengerPost(adult: ['nationality' => 'Ελληνική']))
        ->assertSessionHasErrors('guests.0.nationality');
})->group('fast');

it('offers nationality as a list of countries named in the guest\'s language', function (): void {
    expect(Countries::options('el')['GR'])->toBe('Ελλάδα')
        ->and(Countries::options('en')['DE'])->toBe('Germany')
        ->and(Countries::codes())->not->toContain('EU')
        ->and(Countries::normalise(' gr '))->toBe('GR')
        ->and(Countries::normalise('Greek'))->toBeNull();
})->group('fast');

it('keeps the operator\'s «Χωρίς έγγραφο» when the bands are saved', function (): void {
    [$tenant, $booking] = passengerDraft();

    Tenancy::forTenant($tenant, static function () use ($booking): void {
        $product = $booking->product()->firstOrFail();

        app(SaveAgeBands::class)($product, [
            ['code' => 'adult', 'label' => ['el' => 'Ενήλικας', 'en' => 'Adult'], 'min_age' => 12, 'max_age' => null, 'is_base' => true, 'no_document' => false, 'pricing_mode' => 'fixed'],
            ['code' => 'child', 'label' => ['el' => 'Παιδί', 'en' => 'Child'], 'min_age' => 0, 'max_age' => 11, 'counts_toward_capacity' => true, 'no_document' => true, 'pricing_mode' => 'fixed'],
        ]);

        $bands = AgeBand::query()->where('product_id', $product->getKey())->get()->keyBy('code');

        expect($bands['adult']->no_document)->toBeFalse()
            ->and($bands['child']->isDocumentFree())->toBeTrue();

        // A guest in a document-free band is complete without one.
        $guest = BookingGuest::factory()->create([
            'booking_id' => $booking->getKey(),
            'position' => 9,
            'age_band_id' => $bands['child']->getKey(),
            'full_name' => 'Ελένη',
            'nationality' => 'GR',
            'date_of_birth' => '2020-01-01',
            'document_type' => null,
            'document_number' => null,
        ]);

        expect(SaveGuestDetails::isComplete($guest, true))->toBeTrue();
    });
});
