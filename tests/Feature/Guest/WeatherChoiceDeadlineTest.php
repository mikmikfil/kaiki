<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\ApplyGuestChoice;
use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Enums\WeatherChoice;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

use Tests\Support\Booking\GuestPageScenario;
use Tests\Support\Payments\WebhookScenario;

/*
|--------------------------------------------------------------------------
| The weather choice closes at its deadline (CXL-7)
|--------------------------------------------------------------------------
|
| `weather_choice_due_at` is when the operator's default applies. The sweep
| runs hourly, so for up to an hour after the deadline the page used to keep
| offering the three options and take the guest's answer over the default.
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

it('applies the operator default, not the guest pick, after the deadline', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 12000);

    $tenant->forceFill(['weather_choice_default' => WeatherChoice::Voucher->value])->save();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $booking->forceFill([
            'status' => BookingStatus::Cancelled,
            'cancel_reason' => CancelReason::Weather,
            'cancelled_at' => now()->subDays(14),
            // Passed half an hour ago; the hourly sweep has not run yet.
            'weather_choice_due_at' => now()->subMinutes(30),
        ])->save();
    });

    get('/b/' . $booking->manage_token)
        ->assertOk()
        ->assertDontSee(__('guest.booking.weather.refund'));

    post('/b/' . $booking->manage_token . '/weather-choice', ['choice' => 'refund'])->assertRedirect();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $booking->refresh();

        expect($booking->weather_choice)->toBe(WeatherChoice::Voucher)
            // Nobody chose: the deadline did.
            ->and($booking->weather_choice_ip)->toBeNull();
    });
})->group('fast');

it('refuses a guest choice past the deadline at the action too', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 12000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $booking->forceFill([
            'status' => BookingStatus::Cancelled,
            'cancel_reason' => CancelReason::Weather,
            'cancelled_at' => now()->subDays(14),
            'weather_choice_due_at' => now()->subMinute(),
        ])->save();

        expect(app(ApplyGuestChoice::class)($booking->refresh(), WeatherChoice::Refund))->toBe(0)
            ->and($booking->refresh()->weather_choice)->toBeNull();
    });
})->group('fast');

it('offers the choice before the deadline, dated on the operator clock', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 12000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $booking->forceFill([
            'status' => BookingStatus::Cancelled,
            'cancel_reason' => CancelReason::Weather,
            'cancelled_at' => now(),
            // 01:30 on 5 July in Athens, 4 July in UTC.
            'weather_choice_due_at' => Carbon::parse('2026-07-04 22:30:00', 'UTC'),
        ])->save();
    });

    get('/b/' . $booking->manage_token)
        ->assertOk()
        ->assertSee(__('guest.booking.weather.refund'))
        ->assertSee('05/07/2026')
        ->assertDontSee('04/07/2026');
})->group('fast');
