<?php

declare(strict_types=1);

use App\Domain\Booking\Support\PolicyExplanation;
use App\Enums\BookingStatus;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

use function Pest\Laravel\get;

use Tests\Support\Booking\GuestPageScenario;
use Tests\Support\Payments\WebhookScenario;

/*
|--------------------------------------------------------------------------
| The full cancellation policy at checkout (product owner, 2026-09-17)
|--------------------------------------------------------------------------
|
| A link beside the one-line summary opens the whole ladder, from the booking's
| frozen snapshot, so what the guest reads before paying is what a refund is
| computed from.
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

/** @return array<string, mixed> */
function tieredPolicySnapshot(): array
{
    return [
        'version' => 1,
        'policy_id' => null,
        'name' => ['el' => 'Κανονική', 'en' => 'Standard'],
        'summary' => ['el' => '50% έως 15 ημέρες πριν.', 'en' => '50% up to 15 days before.'],
        'free_cancellation_hours' => null,
        'weather_refund_percent' => 100,
        'force_majeure_voucher_months' => 18,
        'no_show_refund_percent' => 0,
        'tiers' => [['days_before' => 15, 'refund_percent' => 50]],
        'captured_at' => '2026-06-20T09:00:00Z',
    ];
}

it('spells the ladder out the way the refund is computed', function (): void {
    $lines = PolicyExplanation::lines(tieredPolicySnapshot(), 'el');

    expect($lines)->toBe([
        __('guest.policy.tier', ['days' => 15, 'percent' => 50], 'el'),
        __('guest.policy.later', ['days' => 15], 'el'),
        __('guest.policy.weather', ['percent' => 100], 'el'),
        __('guest.policy.voucher', ['months' => 18], 'el'),
        __('guest.policy.no_show', ['percent' => 0], 'el'),
    ]);
})->group('fast');

it('offers the full policy in a window on the checkout page', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 12000);

    Tenancy::forTenant($tenant, static function () use ($booking): void {
        $booking->forceFill([
            'status' => BookingStatus::Draft,
            'paid_cents' => 0,
            'hold_expires_at' => now()->addMinutes(15),
            'policy_snapshot' => tieredPolicySnapshot(),
        ])->save();
    });

    get('/c/' . $booking->manage_token)
        ->assertOk()
        ->assertSee('href="#policy-full"', escape: false)
        ->assertSee('id="policy-full"', escape: false)
        ->assertSee(__('guest.policy.weather', ['percent' => 100], $booking->locale), escape: false);
})->group('fast');
