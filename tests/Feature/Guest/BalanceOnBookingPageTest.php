<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Models\IntegrationCredential;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

use function Pest\Laravel\get;

use Tests\Support\Booking\GuestPageScenario;
use Tests\Support\Payments\WebhookScenario;

/*
|--------------------------------------------------------------------------
| The balance on `/b/{token}` (2026-09-25)
|--------------------------------------------------------------------------
|
| Three things the page got wrong about money still owed: it kept «Υπόλοιπο»
| on a booking that had ended, it printed the due date in UTC, and without a
| gateway its pay button reloaded the page without a word.
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

it('shows no balance on a cancelled or refunded booking', function (BookingStatus $status): void {
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 4000, balanceCents: 8000);

    Tenancy::forTenant($tenant, function () use ($booking, $status): void {
        $booking->forceFill(['status' => $status, 'cancelled_at' => now()])->save();
    });

    get('/b/' . $booking->manage_token)
        ->assertOk()
        ->assertDontSee('<dt class="total">' . __('guest.booking.price.balance') . '</dt>', escape: false)
        ->assertDontSee(__('guest.booking.pay_balance'));
})->with([
    'cancelled' => BookingStatus::Cancelled,
    'refunded' => BookingStatus::Refunded,
])->group('fast');

it('still shows the balance on a confirmed booking', function (): void {
    [, $booking] = GuestPageScenario::booking(paidCents: 4000, balanceCents: 8000);

    get('/b/' . $booking->manage_token)
        ->assertOk()
        ->assertSee('<dt class="total">' . __('guest.booking.price.balance') . '</dt>', escape: false)
        ->assertSee(__('guest.booking.pay_balance'));
})->group('fast');

it('prints the due date on the operator\'s calendar, not UTC\'s', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 4000, balanceCents: 8000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        // 22:30 UTC on 1 July is 01:30 on 2 July in Athens.
        $booking->forceFill(['balance_due_at' => Carbon::parse('2026-07-01 22:30:00', 'UTC')])->save();
    });

    get('/b/' . $booking->manage_token)
        ->assertOk()
        ->assertSee('02/07/2026')
        ->assertDontSee('01/07/2026');
})->group('fast');

it('says so instead of offering a pay button when there is no gateway', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 4000, balanceCents: 8000);

    Tenancy::forTenant($tenant, static function (): void {
        IntegrationCredential::query()->delete();
    });

    get('/b/' . $booking->manage_token)
        ->assertOk()
        ->assertDontSee(__('guest.booking.pay_balance') . '</button>', escape: false)
        ->assertSee(__('guest.booking.pay_balance_offline'));
})->group('fast');
