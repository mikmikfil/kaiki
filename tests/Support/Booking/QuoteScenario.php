<?php

declare(strict_types=1);

namespace Tests\Support\Booking;

use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Enums\QuoteLineKind;
use App\Models\Booking;
use App\Models\CancellationPolicy;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteLineItem;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;

/**
 * A quote-mode booking waiting for an operator to price it.
 *
 * A class rather than Pest helper functions, for the reason `WebhookScenario`
 * and `CancellationScenario` both give: four test files need this, and a
 * `function` in a Pest file is scoped to that file.
 *
 * ## The booking holds nothing, because BKG-25 says it must not
 *
 * `quote_requested`, no `hold_expires_at`, and no `vessel_blocks` row. That is
 * the state the whole milestone's quote flow starts from, and a fixture that
 * quietly armed a hold would make `QuoteHoldsNothingTest` pass against an
 * implementation that held one.
 */
final class QuoteScenario
{
    /**
     * A guest has submitted on a `mode: quote` product.
     *
     * @return array{0: Tenant, 1: Booking}
     */
    public static function requested(): array
    {
        $tenant = Tenant::factory()->create();

        $booking = Tenancy::forTenant($tenant, static function (): Booking {
            /** @var CancellationPolicy $policy */
            $policy = CancellationPolicy::factory()->withTiers()->create();

            /** @var Vessel $vessel */
            $vessel = Vessel::factory()->create();

            /** @var Product $product */
            $product = Product::factory()->create([
                'mode' => BookingMode::Quote,
                'vessel_id' => $vessel->getKey(),
                'cancellation_policy_id' => $policy->getKey(),
            ]);

            return Booking::factory()->create([
                'product_id' => $product->getKey(),
                'vessel_id' => $vessel->getKey(),
                'departure_id' => null,
                'mode' => BookingMode::Quote,
                'status' => BookingStatus::QuoteRequested,
                // BKG-24: **no price is shown at any point** before the quote,
                // so the booking genuinely has none.
                'subtotal_cents' => 0,
                'extras_cents' => 0,
                'discount_cents' => 0,
                'total_cents' => 0,
                'deposit_cents' => 0,
                'paid_cents' => 0,
                'balance_cents' => 0,
                'price_snapshot' => null,
                'policy_snapshot' => null,
                'confirmed_at' => null,
                'hold_expires_at' => null,
            ]);
        });

        return [$tenant, $booking];
    }

    /**
     * The same, plus a draft quote with one charter line.
     *
     * @return array{0: Tenant, 1: Booking, 2: Quote}
     */
    public static function drafted(int $charterCents = 95000): array
    {
        [$tenant, $booking] = self::requested();

        $quote = Tenancy::forTenant($tenant, static function () use ($booking, $charterCents): Quote {
            /** @var Quote $quote */
            $quote = Quote::factory()->create([
                'booking_id' => $booking->getKey(),
                'subtotal_cents' => $charterCents,
                'total_cents' => $charterCents,
            ]);

            QuoteLineItem::factory()->create([
                'quote_id' => $quote->getKey(),
                'kind' => QuoteLineKind::Charter,
                'unit_price_cents' => $charterCents,
                'total_cents' => $charterCents,
            ]);

            return $quote;
        });

        return [$tenant, $booking, $quote];
    }
}
