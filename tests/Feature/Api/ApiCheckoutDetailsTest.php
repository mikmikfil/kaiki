<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\SaveGuestDetails;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingAnswer;
use App\Models\TripQuestion;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\postJson;

use Tests\Support\Api\BookingApiScenario;
use Tests\Support\Api\CatalogRequest;

/*
|--------------------------------------------------------------------------
| The API checkout asks what the checkout page asks (audit 2, 2026-09-25)
|--------------------------------------------------------------------------
|
| The Kaiki widget hands the guest to the hosted checkout (`checkout_url`),
| which will not pay without every passenger on a trip with «Στοιχεία
| επιβατών», nor without the trip's required questions. The API takes neither,
| so `POST /bookings/{uuid}/checkout` now refuses such a draft the same way and
| the integrator sends the guest to `checkout_url`.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-06-01 09:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @return array{0: array<string, mixed>, 1: TestResponse<JsonResponse>} */
function apiDraft(bool $passengers = false, bool $question = false): array
{
    $fixture = BookingApiScenario::bookable(startsAt: Carbon::parse('2026-07-01 09:00:00'));

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture, $passengers, $question): void {
        $fixture['product']->forceFill(['guest_details_required' => $passengers])->save();

        if ($question) {
            TripQuestion::factory()->create(['product_id' => $fixture['product']->getKey()]);
        }
    });

    $created = postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band']),
        ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => BookingApiScenario::idempotencyKey()],
    )->assertCreated();

    return [$fixture, $created];
}

/**
 * @param  array<string, mixed>  $fixture
 * @param  TestResponse<JsonResponse>  $created
 * @return TestResponse<JsonResponse>
 */
function apiCheckout(array $fixture, TestResponse $created): TestResponse
{
    return postJson(
        CatalogRequest::url('/bookings/' . $created->json('data.uuid') . '/checkout'),
        ['kind' => 'full', 'return_url' => 'https://aegeancruises.gr/thanks'],
        [
            'Authorization' => "Bearer {$fixture['key']}",
            'X-Kaiki-Guest-Token' => $created->json('data.manage_token'),
            'Idempotency-Key' => BookingApiScenario::idempotencyKey(),
        ],
    );
}

it('refuses to pay for a trip that asks for passengers until every one is filled in', function (): void {
    [$fixture, $created] = apiDraft(passengers: true);

    apiCheckout($fixture, $created)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'passengers_required');

    Tenancy::forTenant($fixture['tenant'], function () use ($created): void {
        $booking = Booking::query()->where('uuid', $created->json('data.uuid'))->sole();

        expect($booking->status)->toBe(BookingStatus::Draft);

        $rows = [];

        foreach ([1, 2] as $position) {
            $rows[] = [
                'position' => $position,
                'full_name' => "Επιβάτης $position",
                'date_of_birth' => '1985-03-01',
                'nationality' => 'GR',
                'sex' => 'm',
                'document_type' => 'id_card',
                'document_number' => "AK00$position",
            ];
        }

        // As the checkout page would have saved them.
        app(SaveGuestDetails::class)($booking, $rows);
    });

    expect(apiCheckout($fixture, $created)->json('error.code'))->not->toBe('passengers_required');
})->group('fast');

it('refuses to pay while a required trip question is unanswered', function (): void {
    [$fixture, $created] = apiDraft(question: true);

    apiCheckout($fixture, $created)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'answers_required');

    Tenancy::forTenant($fixture['tenant'], function () use ($created): void {
        $booking = Booking::query()->where('uuid', $created->json('data.uuid'))->sole();
        $question = TripQuestion::query()->sole();

        BookingAnswer::query()->create([
            'tenant_id' => $booking->tenant_id,
            'booking_id' => $booking->getKey(),
            'booking_guest_id' => null,
            'trip_question_id' => $question->getKey(),
            'question' => $question->snapshot(),
            'answer' => 'yes',
        ]);
    });

    expect(apiCheckout($fixture, $created)->json('error.code'))->not->toBe('answers_required');
})->group('fast');

it('lets a trip that asks for nothing pay as before', function (): void {
    [$fixture, $created] = apiDraft();

    expect(apiCheckout($fixture, $created)->json('error.code'))->not->toBeIn(['passengers_required', 'answers_required']);
})->group('fast');
