<?php

declare(strict_types=1);

namespace Tests\Support\Booking;

use App\Data\Pricing\CancellationPolicyData;
use App\Domain\Booking\Support\GuestTokenResolver;
use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Enums\GuestDetailsStatus;
use App\Enums\IntegrationProvider;
use App\Enums\PaymentGatewayName;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\CancellationPolicy;
use App\Models\Departure;
use App\Models\IntegrationCredential;
use App\Models\Payment;
use App\Models\Port;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Str;

/**
 * A booking with live guest tokens, ready for `/b/`, `/g/` and `/v/`.
 *
 * A class rather than Pest helpers, for the reason the other three scenario
 * classes give: five test files need this and a `function` in a Pest file is
 * scoped to that file.
 *
 * ## The brand profile is already there, and this file does not create one
 *
 * TOK-5 requires these pages to be *"fully branded (BRD-1)"*, and
 * `GetBrandPayload` calls `firstOrFail()` on `brand_profiles` — so a tenant
 * without one would 500 rather than render unbranded. `TenantObserver` creates
 * it with the tenant (#17), and `brand_profiles.tenant_id` is **unique**, so a
 * fixture that made a second one would fail on the index rather than on the
 * feature. Every test here therefore exercises the real branding path.
 */
final class GuestPageScenario
{
    /**
     * A confirmed booking whose manage and guest-details tokens both work.
     *
     * @return array{0: Tenant, 1: Booking}
     */
    public static function booking(
        int $paidCents = 12000,
        int $balanceCents = 0,
        bool $documentsRequired = false,
        BookingMode $mode = BookingMode::PerSeat,
        int $pax = 2,
    ): array {
        $tenant = Tenant::factory()->create();

        $booking = Tenancy::forTenant($tenant, static function () use (
            $paidCents,
            $balanceCents,
            $documentsRequired,
            $mode,
            $pax,
        ): Booking {
            // The gateway these pages refund and re-charge through. Without
            // one, `RefundBooking` finds no charge to reverse and writes no
            // refund row — which would let a test about refund *amounts* pass
            // against a booking that refunds nothing.
            IntegrationCredential::factory()
                ->forProvider(IntegrationProvider::Viva)
                ->live()->verified()->default()->create();

            /** @var CancellationPolicy $policy */
            $policy = CancellationPolicy::factory()
                ->withTiers()
                // **No free-cancellation window**, so the tier ladder decides.
                // The factory's default of 48 hours would return 100% for every
                // cancellation more than two days out, and every refund
                // assertion in this group would pass without the ladder ever
                // being consulted.
                ->create(['free_cancellation_hours' => null]);

            /** @var Port $port */
            $port = Port::factory()->create();

            /** @var Vessel $vessel */
            $vessel = Vessel::factory()->create();

            /** @var Product $product */
            $product = Product::factory()->create([
                'vessel_id' => $vessel->getKey(),
                'meeting_point_id' => $port->getKey(),
                'cancellation_policy_id' => $policy->getKey(),
                'guest_details_required' => $documentsRequired,
            ]);

            $departure = Departure::factory()->create([
                'product_id' => $product->getKey(),
                'vessel_id' => $vessel->getKey(),
                'capacity' => 12,
                'seats_sold' => $pax,
                'seats_held' => 0,
                'min_pax' => 0,
            ]);

            /** @var Booking $booking */
            $booking = Booking::factory()
                ->forDeparture($departure)
                ->withPax($pax, $pax)
                ->create([
                    'mode' => $mode,
                    'vessel_id' => $vessel->getKey(),
                    'status' => BookingStatus::Confirmed,
                    'total_cents' => $paidCents + $balanceCents,
                    'paid_cents' => $paidCents,
                    'balance_cents' => $balanceCents,
                    'policy_snapshot' => CancellationPolicyData::fromModel($policy->fresh(['tiers']))->toSnapshot(),
                    // TOK-2's two tokens, minted the way the product mints them.
                    'manage_token' => GuestTokenResolver::mint(),
                    'guest_details_token' => GuestTokenResolver::mint(),
                    'guest_details_status' => $documentsRequired
                        ? GuestDetailsStatus::Pending
                        : GuestDetailsStatus::NotRequired,
                    'terms_accepted_at' => null,
                ]);

            if ($paidCents > 0) {
                // A settled charge, so there is something to refund. §2.5's
                // `payments` row rather than a `paid_cents` column set by hand:
                // PAY-10 recomputes the booking's money from these rows, and a
                // booking whose column disagreed with its rows would be exactly
                // the discrepancy that invariant exists to prevent.
                Payment::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'booking_id' => $booking->getKey(),
                    'gateway' => PaymentGatewayName::Viva,
                    'kind' => PaymentKind::Full,
                    'amount_cents' => $paidCents,
                    'currency' => 'EUR',
                    'status' => PaymentStatus::Succeeded,
                    'gateway_ref' => 'viva_' . Str::random(10),
                    'idempotency_key' => (string) Str::uuid(),
                    'paid_at' => now(),
                ]);
            }

            // TOK-8: the rows are fixed by the pax breakdown, and they exist
            // before the guest ever opens the page — the form writes into them
            // and creates none.
            for ($position = 1; $position <= $pax; $position++) {
                BookingGuest::factory()->create([
                    'booking_id' => $booking->getKey(),
                    'position' => $position,
                    'full_name' => null,
                    'is_lead' => $position === 1,
                    'ticket_code' => Str::random(12),
                ]);
            }

            return $booking;
        });

        return [$tenant, $booking->refresh()];
    }
}
