<?php

declare(strict_types=1);

use App\Domain\Pricing\Actions\ApplyDiscountCode;
use App\Domain\Pricing\Actions\ApplyVoucher;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\DiscountCode;
use App\Models\RatePlan;
use App\Models\Tenant;
use App\Models\Voucher;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Tests\Support\Booking\GuestPageScenario;

/*
|--------------------------------------------------------------------------
| PRC-25: the deposit follows the total down (2026-09-25)
|--------------------------------------------------------------------------
|
| A €300 trip at 30% with a €100 code used to rewrite the snapshot's deposit
| and leave `deposit_cents` — the column the checkout charges — at €90. Both
| move now, after a discount code and after a voucher.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-06-20 09:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * A €300 draft on a 30% plan, from an operator who takes deposits.
 *
 * @return array{0: Tenant, 1: Booking}
 */
function depositDraft(bool $paysInFull = false): array
{
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 30000);

    $tenant->forceFill(['deposits_enabled' => true])->save();

    Tenancy::forTenant($tenant, static function () use ($booking, $paysInFull): void {
        $plan = RatePlan::factory()->depositPercent(30)->create(['product_id' => $booking->product_id]);

        $booking->forceFill([
            'status' => BookingStatus::Draft,
            'paid_cents' => 0,
            'balance_cents' => 30000,
            'hold_expires_at' => now()->addMinutes(15),
            'subtotal_cents' => 30000,
            'extras_cents' => 0,
            'discount_cents' => 0,
            'total_cents' => 30000,
            'deposit_cents' => $paysInFull ? 30000 : 9000,
            'price_snapshot' => [
                'lines' => [],
                'rate_plan_id' => $plan->getKey(),
                'subtotal_cents' => 30000,
                'extras_cents' => 0,
                'discount_cents' => 0,
                'total_cents' => 30000,
                'deposit' => ['type' => 'percent', 'percent' => 30, 'amount_cents' => 9000],
            ],
        ])->save();
    });

    return [$tenant, $booking->refresh()];
}

it('takes the deposit from the total after a discount code, in the column the checkout charges', function (): void {
    [$tenant, $booking] = depositDraft();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        DiscountCode::factory()->create(['code' => 'MINUS100', 'kind' => 'fixed', 'value' => 10000]);

        app(ApplyDiscountCode::class)($booking, 'MINUS100');

        $booking->refresh();

        expect($booking->total_cents)->toBe(20000)
            ->and($booking->price_snapshot['deposit']['amount_cents'])->toBe(6000)
            ->and($booking->deposit_cents)->toBe(6000);

        // Taken off again: back to 30% of €300.
        app(ApplyDiscountCode::class)($booking, null);

        expect($booking->refresh()->deposit_cents)->toBe(9000);
    });
})->group('fast');

it('keeps a booking paying in full paying in full after a code', function (): void {
    [$tenant, $booking] = depositDraft(paysInFull: true);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        DiscountCode::factory()->create(['code' => 'MINUS100', 'kind' => 'fixed', 'value' => 10000]);

        app(ApplyDiscountCode::class)($booking, 'MINUS100');

        expect($booking->refresh()->deposit_cents)->toBe(20000);
    });
})->group('fast');

it('takes the deposit from the total after a voucher', function (): void {
    [$tenant, $booking] = depositDraft();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $voucher = Voucher::factory()->create(['amount_cents' => 10000, 'remaining_cents' => 10000]);

        $booking->forceFill(['voucher_id' => $voucher->getKey()])->save();

        app(ApplyVoucher::class)($booking);

        $booking->refresh();

        expect($booking->total_cents)->toBe(20000)
            ->and($booking->deposit_cents)->toBe(6000)
            ->and($booking->price_snapshot['deposit']['amount_cents'])->toBe(6000);

        // Confirmation applies the same voucher again: nothing moves.
        app(ApplyVoucher::class)($booking);

        expect($booking->refresh()->deposit_cents)->toBe(6000);
    });
})->group('fast');
