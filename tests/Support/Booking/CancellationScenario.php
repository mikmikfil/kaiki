<?php

declare(strict_types=1);

namespace Tests\Support\Booking;

use App\Data\Pricing\CancellationPolicyData;
use App\Enums\BookingStatus;
use App\Enums\IntegrationProvider;
use App\Enums\PaymentGatewayName;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\CancellationPolicy;
use App\Models\Departure;
use App\Models\IntegrationCredential;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * A confirmed booking with a frozen policy, ready to be cancelled.
 *
 * A class rather than Pest helper functions for the reason `WebhookScenario`
 * gives: five test files need this, and a `function` in a Pest file is scoped
 * to that file — the second file to call it fails with "undefined function",
 * which reads as a broken test rather than a missing import.
 *
 * ## The snapshot is taken from a real policy, then the policy is left behind
 *
 * CXL-1 is what most of these tests are about, and a fixture that wrote a
 * hand-built array into `policy_snapshot` would prove nothing about the code
 * that *takes* the snapshot. So a `CancellationPolicy` with real tiers is
 * created, frozen through {@see CancellationPolicyData::fromModel()}, and
 * handed back — so a test can edit the live one and assert the refund did not
 * move.
 */
final class CancellationScenario
{
    /** The Viva order code the succeeded payment carries. */
    public const REFERENCE = 'viva_order_test';

    /**
     * A confirmed booking on a departure, paid in cash through a gateway.
     *
     * @param  array<int, int>  $ladder  days_before => refund_percent
     * @return array{0: Tenant, 1: Booking, 2: CancellationPolicy}
     */
    public static function make(
        int $paidCents = 12000,
        array $ladder = [15 => 100, 7 => 50, 2 => 0],
        ?int $freeCancellationHours = null,
        int $weatherRefundPercent = 100,
        int $capacity = 10,
    ): array {
        $tenant = Tenant::factory()->create();

        [$booking, $policy] = Tenancy::forTenant($tenant, static function () use (
            $paidCents,
            $ladder,
            $freeCancellationHours,
            $weatherRefundPercent,
            $capacity,
        ): array {
            IntegrationCredential::factory()
                ->forProvider(IntegrationProvider::Viva)
                ->live()
                ->verified()
                ->default()
                ->create();

            /** @var CancellationPolicy $policy */
            $policy = CancellationPolicy::factory()
                ->withTiers($ladder)
                ->create([
                    'free_cancellation_hours' => $freeCancellationHours,
                    'weather_refund_percent' => $weatherRefundPercent,
                ]);

            $departure = Departure::factory()->create([
                'capacity' => $capacity,
                // The booking below is committed, so its pax are already in
                // `seats_sold` — which is the state a cancellation arrives into
                // and the number CXL-9 gives back.
                'seats_sold' => 2,
                'seats_held' => 0,
                'min_pax' => 0,
            ]);

            $booking = Booking::factory()
                ->forDeparture($departure)
                ->withPax(2, 2)
                ->create([
                    'status' => BookingStatus::Confirmed,
                    'total_cents' => $paidCents,
                    'paid_cents' => $paidCents,
                    'balance_cents' => 0,
                    // **Frozen from the live policy**, not hand-built. See the
                    // class docblock: the tests that matter edit the live one
                    // afterwards.
                    'policy_snapshot' => CancellationPolicyData::fromModel($policy->fresh(['tiers']))->toSnapshot(),
                ]);

            Payment::query()->create([
                'uuid' => (string) Str::uuid(),
                'booking_id' => $booking->getKey(),
                'gateway' => PaymentGatewayName::Viva,
                'kind' => PaymentKind::Full,
                'amount_cents' => $paidCents,
                'currency' => 'EUR',
                'status' => PaymentStatus::Succeeded,
                'gateway_ref' => self::REFERENCE,
                'idempotency_key' => (string) Str::uuid(),
                'paid_at' => now(),
            ]);

            return [$booking, $policy];
        });

        return [$tenant, $booking, $policy];
    }

    /**
     * ADR-0017's own example: €200 paid with a €120 voucher and €80 cash.
     *
     * The numbers are the ADR's rather than round ones, because the split it
     * specifies — €60 and €40 under a 50% policy — is the assertion, and a
     * fixture that used €100/€100 would pass under an implementation that
     * halved everything regardless.
     *
     * @param  array<int, int>  $ladder  days_before => refund_percent
     * @return array{0: Tenant, 1: Booking, 2: Voucher}
     */
    public static function paidWithVoucher(
        int $totalCents = 20000,
        int $voucherCents = 12000,
        int $cashCents = 8000,
        array $ladder = [15 => 100, 7 => 50, 2 => 0],
        bool $voucherExpired = false,
    ): array {
        [$tenant, $booking] = self::make(paidCents: $cashCents, ladder: $ladder);

        $voucher = Tenancy::forTenant($tenant, static function () use (
            $booking,
            $totalCents,
            $voucherCents,
            $cashCents,
            $voucherExpired,
        ): Voucher {
            /** @var Voucher $voucher */
            $voucher = Voucher::factory()
                ->when($voucherExpired, static fn ($factory) => $factory->lapsed())
                ->create([
                    'amount_cents' => $voucherCents,
                    'remaining_cents' => 0,
                ]);

            VoucherRedemption::query()->create([
                'voucher_id' => $voucher->getKey(),
                'booking_id' => $booking->getKey(),
                'amount_cents' => $voucherCents,
                'redeemed_at' => now(),
            ]);

            $booking->forceFill([
                'voucher_id' => $voucher->getKey(),
                'subtotal_cents' => $totalCents,
                'total_cents' => $totalCents - $voucherCents,
                // `discount_cents` is what the voucher covered; `paid_cents` is
                // the cash. The pro-rata split is of the two together.
                'discount_cents' => $voucherCents,
                'paid_cents' => $cashCents,
                'balance_cents' => 0,
            ])->save();

            return $voucher;
        });

        return [$tenant, $booking->refresh(), $voucher];
    }

    /**
     * The gateway responses a refund needs, recorded.
     *
     * The same two layers as `WebhookScenario`: this fake, plus `phpunit.xml`
     * pinning every gateway host to `.test` so a request escaping it cannot
     * resolve. A file that forgets this does not fail loudly — it makes a
     * network call.
     */
    public static function fakeGatewayResponses(): void
    {
        Http::fake([
            '*accounts*/connect/token' => Http::response(['access_token' => 'tok_test', 'expires_in' => 3600]),
            '*/api/transactions/*' => Http::response(['TransactionId' => 'viva_re_test']),
            '*/v1/refunds' => Http::response(['id' => 're_test_a1b2c3']),
        ]);
    }

    /** A gateway that refuses the refund — CXL-10's failure branch. */
    public static function fakeRefusedRefund(string $code = 'charge_too_old'): void
    {
        Http::fake([
            '*accounts*/connect/token' => Http::response(['access_token' => 'tok_test', 'expires_in' => 3600]),
            '*/api/transactions/*' => Http::response(['ErrorCode' => $code], 400),
            '*/v1/refunds' => Http::response(['error' => ['code' => $code]], 400),
        ]);
    }
}
