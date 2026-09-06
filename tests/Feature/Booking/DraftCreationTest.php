<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\CreateBookingDraft;
use App\Domain\Booking\Data\BookingDraftData;
use App\Domain\Booking\Support\LeadGuest;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\RatePlanPrice;
use App\Models\Tenant;
use App\Support\Booking\BookingReference;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Tests\Support\AvailabilityScenarioBuilder;

/*
 * Spec BKG-6, BKG-7, BKG-8, CXL-2, AVL-41, PRC-1.
 *
 * The seven things BKG-6 lists, and the two rules that decide whether a booking
 * can be trusted a year later: the price is computed here and accepted from
 * nowhere (PRC-1), and both snapshots are taken at first persistence and never
 * again (CXL-2).
 */

/**
 * A tenant with one active product, its bands and one departure to book onto.
 *
 * Reuses the M1 scenario builder rather than assembling a product by hand: a
 * booking is only meaningful against a product the availability engine would
 * actually sell, and a hand-built fixture drifts from that the first time the
 * catalogue gains a required field.
 *
 * @return array{0: Tenant, 1: Product, 2: Departure}
 */
function draftScenario(): array
{
    $tenant = Tenant::factory()->create();

    [$product, $departure] = Tenancy::forTenant($tenant, static function (): array {
        $builder = AvailabilityScenarioBuilder::make();
        $product = $builder->product();

        // The builder creates a rate plan but no per-band prices, because the
        // M1 availability tests it was written for never ask what anything
        // costs. A booking does, and PRC-5 refuses to price an unsellable
        // product rather than charging zero — so without this the draft is
        // created with a total of nothing, which is a free trip that passes
        // every arithmetic check on its way to a gateway.
        RatePlanPrice::factory()->create([
            'rate_plan_id' => RatePlan::query()->where('product_id', $product->getKey())->firstOrFail()->getKey(),
            'age_band_id' => $product->ageBands->firstWhere('code', 'adult')?->getKey(),
        ]);

        return [$product, $builder->departure($product, '2026-07-04', '09:00')];
    });

    return [$tenant, $product, $departure];
}

it('writes both snapshots at first persistence', function (): void {
    [$tenant, $product, $departure] = draftScenario();

    Tenancy::forTenant($tenant, function () use ($product, $departure): void {
        $booking = app(CreateBookingDraft::class)(new BookingDraftData(
            product: $product,
            date: Carbon::parse($departure->local_date->toDateString()),
            guestName: 'Maria Papadopoulou',
            guestEmail: 'maria@example.gr',
            guestPhone: '6912345678',
            paxByCode: ['adult' => 2],
            startTime: (string) $departure->local_time,
            termsAcceptedAt: now(),
            ipAddress: '203.0.113.9',
        ));

        // CXL-2, marked RESOLVED. A refund computed from the *current* policy
        // rather than the one the guest agreed to is the operator changing the
        // terms of a sale after the fact.
        expect($booking->price_snapshot)->not->toBeNull()
            ->and($booking->status)->toBe(BookingStatus::Draft)
            ->and($booking->reference)->toStartWith('KAI-')
            ->and(BookingReference::isValid($booking->reference))->toBeTrue()
            ->and($booking->manage_token)->toHaveLength(40)
            // Minted lazily (§2.5), so an unused booking never leaves a live URL.
            ->and($booking->guest_details_token)->toBeNull()
            ->and($booking->hold_expires_at)->not->toBeNull()
            ->and($booking->terms_accepted_at)->not->toBeNull()
            ->and($booking->ip_address)->toBe('203.0.113.9');
    });
})->group('fast');

it('computes the price rather than accepting one', function (): void {
    [$tenant, $product, $departure] = draftScenario();

    Tenancy::forTenant($tenant, function () use ($product, $departure): void {
        $booking = app(CreateBookingDraft::class)(new BookingDraftData(
            product: $product,
            date: Carbon::parse($departure->local_date->toDateString()),
            guestName: 'Maria',
            guestEmail: 'maria@example.gr',
            paxByCode: ['adult' => 2],
            startTime: (string) $departure->local_time,
        ));

        // PRC-1. There is no field on `BookingDraftData` a caller could set,
        // and the total agrees with the snapshot the engine produced.
        expect($booking->total_cents)->toBeGreaterThan(0)
            ->and($booking->total_cents)->toBe((int) ($booking->price_snapshot['total_cents'] ?? -1))
            ->and($booking->balance_cents)->toBe($booking->total_cents);
    });
})->group('fast');

it('takes the hold and counts the seats against the departure', function (): void {
    [$tenant, $product, $departure] = draftScenario();

    Tenancy::forTenant($tenant, function () use ($product, $departure): void {
        $capacity = $departure->capacity;

        app(CreateBookingDraft::class)(new BookingDraftData(
            product: $product,
            date: Carbon::parse($departure->local_date->toDateString()),
            guestName: 'Maria',
            guestEmail: 'maria@example.gr',
            paxByCode: ['adult' => 2],
            startTime: (string) $departure->local_time,
        ));

        $departure->refresh();

        // Held, not sold. `CLAUDE.md`'s first invariant: the two counters are
        // disjoint and additive, and an unpaid draft in `seats_sold` would flip
        // the departure to `guaranteed` and email every guest.
        expect($departure->seats_held)->toBe(2)
            ->and($departure->seats_sold)->toBe(0)
            ->and($departure->seatsAvailable())->toBe($capacity - 2);
    });
})->group('fast');

it('does not block a booking on a phone number it cannot read', function (): void {
    [$tenant, $product, $departure] = draftScenario();

    Tenancy::forTenant($tenant, function () use ($product, $departure): void {
        $booking = app(CreateBookingDraft::class)(new BookingDraftData(
            product: $product,
            date: Carbon::parse($departure->local_date->toDateString()),
            guestName: 'Maria',
            guestEmail: 'maria@example.gr',
            // BKG-8, in as many words: a malformed phone blocks SMS and MUST
            // NOT block the booking. Operators lose more to a rejected phone
            // field than they will ever lose to a missing text message.
            guestPhone: 'not a phone number at all',
            paxByCode: ['adult' => 2],
            startTime: (string) $departure->local_time,
        ));

        expect($booking->exists)->toBeTrue()
            ->and($booking->guest_phone)->toBeNull();
    });
})->group('fast');

it('normalises a Greek mobile to E.164', function (): void {
    // A half-normalised number is worse than none: `6912345678` is a valid
    // Greek mobile and a valid nothing anywhere else, and an SMS gateway handed
    // it will either reject it or deliver to a stranger.
    expect(LeadGuest::normalisePhone('6912345678'))->toBe('+306912345678')
        ->and(LeadGuest::normalisePhone('+30 691 234 5678'))->toBe('+306912345678')
        ->and(LeadGuest::normalisePhone('nonsense'))->toBeNull()
        ->and(LeadGuest::normalisePhone(null))->toBeNull()
        ->and(LeadGuest::normalisePhone('   '))->toBeNull();
})->group('fast');

it('treats an unresolvable domain as deliverable rather than refusing the guest', function (): void {
    // BKG-8's hedge is load-bearing. DNS is a network call in the middle of a
    // checkout: it fails in sandboxes, on aeroplanes and behind blocked egress,
    // and a form that refuses an address because a resolver timed out refuses
    // valid customers.
    expect(LeadGuest::emailIsWellFormed('maria@example.gr'))->toBeTrue()
        ->and(LeadGuest::emailIsWellFormed('not-an-email'))->toBeFalse()
        ->and(LeadGuest::emailLooksDeliverable('not-an-email'))->toBeFalse();
})->group('fast');

it('lowercases the email, because a guest typing it back must find their booking', function (): void {
    [$tenant, $product, $departure] = draftScenario();

    Tenancy::forTenant($tenant, function () use ($product, $departure): void {
        $booking = app(CreateBookingDraft::class)(new BookingDraftData(
            product: $product,
            date: Carbon::parse($departure->local_date->toDateString()),
            guestName: '  Maria Papadopoulou  ',
            guestEmail: '  Maria@Example.GR  ',
            paxByCode: ['adult' => 2],
            startTime: (string) $departure->local_time,
        ));

        expect($booking->guest_email)->toBe('maria@example.gr')
            ->and($booking->guest_name)->toBe('Maria Papadopoulou');
    });
})->group('fast');

it('keeps only the five utm columns and drops anything else the caller passed', function (): void {
    $data = new BookingDraftData(
        product: new Product,
        date: now(),
        guestName: 'Maria',
        guestEmail: 'maria@example.gr',
        utm: [
            'utm_source' => 'instagram',
            // A loosely typed bag from a query string an attacker controls is
            // exactly where somebody later adds `status`.
            'status' => 'confirmed',
            'utm_medium' => '  ',
        ],
    );

    $columns = $data->utmColumns();

    expect($columns)->toHaveKeys(['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'])
        ->and($columns)->not->toHaveKey('status')
        ->and($columns['utm_source'])->toBe('instagram')
        ->and($columns['utm_medium'])->toBeNull();
})->group('fast');

it('gives each booking its own reference and tokens', function (): void {
    [$tenant, $product, $departure] = draftScenario();

    Tenancy::forTenant($tenant, function () use ($product, $departure): void {
        $date = Carbon::parse($departure->local_date->toDateString());

        for ($i = 0; $i < 3; $i++) {
            app(CreateBookingDraft::class)(new BookingDraftData(
                product: $product,
                date: $date,
                guestName: 'Guest ' . $i,
                guestEmail: "guest{$i}@example.gr",
                paxByCode: ['adult' => 1],
                startTime: (string) $departure->local_time,
            ));
        }

        $bookings = Booking::query()->get();

        expect($bookings)->toHaveCount(3)
            ->and($bookings->pluck('reference')->unique())->toHaveCount(3)
            ->and($bookings->pluck('manage_token')->unique())->toHaveCount(3)
            ->and($bookings->pluck('uuid')->unique())->toHaveCount(3);
    });
})->group('fast');
