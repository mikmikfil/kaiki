<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Models\Booking;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\postJson;

use Tests\Support\Api\BookingApiScenario;
use Tests\Support\Api\CatalogRequest;

/*
|--------------------------------------------------------------------------
| CXL-4 on the API (audit, 2026-09-25)
|--------------------------------------------------------------------------
|
| Once the boat has left, a cancellation is the operator's to record. The
| manage page already hid its button; `POST /bookings/{uuid}/cancel` did not
| ask, released the seats of a sailing at sea, and answered an expired booking
| with a 500.
|
*/

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @return array{0: Booking, 1: array<string, string>, 2: Tenant} */
function apiBookingBeforeSailing(): array
{
    $fixture = BookingApiScenario::bookable(startsAt: now()->addDays(10)->setTime(9, 0));

    $created = postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band']),
        ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => BookingApiScenario::idempotencyKey()],
    );

    $booking = Tenancy::forTenant(
        $fixture['tenant'],
        fn (): Booking => Booking::query()->where('uuid', $created->json('data.uuid'))->sole(),
    );

    BookingApiScenario::confirm($fixture['tenant'], $booking, 13000);

    return [$booking->refresh(), [
        'Authorization' => "Bearer {$fixture['key']}",
        'X-Kaiki-Guest-Token' => (string) $created->json('data.manage_token'),
    ], $fixture['tenant']];
}

/**
 * @param  array<string, string>  $headers
 * @return TestResponse<JsonResponse>
 */
function postGuestCancellation(Booking $booking, array $headers): TestResponse
{
    return postJson(
        CatalogRequest::url('/bookings/' . $booking->uuid . '/cancel'),
        ['dry_run' => false],
        [...$headers, 'Idempotency-Key' => BookingApiScenario::idempotencyKey()],
    );
}

it('refuses a guest cancellation once the trip has started', function (): void {
    [$booking, $headers, $tenant] = apiBookingBeforeSailing();

    Carbon::setTestNow($booking->starts_at_utc->copy()->addHour());

    postGuestCancellation($booking, $headers)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'booking_not_cancellable');

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect($booking->refresh()->status)->toBe(BookingStatus::Confirmed);
    });
})->group('fast');

it('answers an expired booking with a 409, not a 500', function (): void {
    [$booking, $headers, $tenant] = apiBookingBeforeSailing();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $booking->forceFill(['status' => BookingStatus::Expired, 'cancel_reason' => CancelReason::HoldExpired])->save();
    });

    postGuestCancellation($booking, $headers)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'booking_not_cancellable');
})->group('fast');

it('still cancels before the trip starts', function (): void {
    [$booking, $headers] = apiBookingBeforeSailing();

    postGuestCancellation($booking, $headers)
        ->assertOk()
        ->assertJsonPath('data.status', BookingStatus::Cancelled->value);
})->group('fast');
