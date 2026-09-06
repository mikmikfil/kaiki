<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\CreateManualBooking;
use App\Domain\Booking\Data\BookingDraftData;
use App\Domain\Booking\Data\ManualBookingAdjustment;
use App\Enums\AuditAction;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\PaymentGatewayName;
use App\Exceptions\HoldRefused;
use App\Models\AuditLog;
use App\Models\Departure;
use App\Models\Payment;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Tests\Support\Api\BookingApiScenario;

/*
|--------------------------------------------------------------------------
| The booking taken on the phone — spec BKG-30 to BKG-33
|--------------------------------------------------------------------------
|
| BKG-32 contains two sentences that contradict each other read plainly:
|
| > A manual booking may exceed `min_lead_time_hours` and `max_advance_days`
| > restrictions but **MUST NOT exceed capacity or the legal `capacity_max`**
| > (AVL-25). **Capacity override requires an explicit confirmation and is
| > logged.**
|
| The reconciliation, and the only reading in which both are true: the **legal**
| `capacity_max` is a certificate and has no override at all; the **departure's**
| capacity is a commercial number the operator chose and can be exceeded with an
| explicit confirmation and an audit row.
|
| An operator may squeeze one more person onto a boat they under-sold. They may
| not sail illegally full. Both halves are asserted here, because an
| implementation that satisfied only one of them would look correct.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * The draft data an operator's form produces.
 *
 * @param  array<string, mixed>  $fixture
 */
function manualDraft(array $fixture, int $qty = 2): BookingDraftData
{
    return new BookingDraftData(
        product: $fixture['product'],
        date: $fixture['departure']->local_date->copy(),
        guestName: 'Γιώργος Νικολάου',
        guestEmail: 'giorgos@example.gr',
        guestPhone: '+306912345678',
        paxByCode: ['adult' => $qty],
    );
}

it('takes a manual booking through the same pricing engine', function (): void {
    $fixture = BookingApiScenario::bookable(unitPriceCents: 6500);

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        $booking = app(CreateManualBooking::class)(manualDraft($fixture));

        // BKG-31: "the same availability and pricing engine". An operator
        // quoting on the phone must be quoting the website's price.
        expect($booking->total_cents)->toBe(13000)
            ->and($booking->source)->toBe(BookingSource::Manual)
            ->and($booking->status)->toBe(BookingStatus::Draft)
            // The frozen policy, exactly as a guest booking gets it (CXL-2).
            ->and($booking->policy_snapshot)->not->toBeNull();
    });
})->group('fast');

it('sets the source itself and ignores what the caller claimed', function (): void {
    $fixture = BookingApiScenario::bookable();

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        $data = new BookingDraftData(
            product: $fixture['product'],
            date: $fixture['departure']->local_date->copy(),
            guestName: 'Γιώργος Νικολάου',
            guestEmail: 'giorgos@example.gr',
            source: BookingSource::Widget,
            paxByCode: ['adult' => 1],
        );

        // A manual booking claiming to be a widget booking would be invisible
        // in every report that separates the two — and the public API rejects
        // `manual` for the mirror-image reason.
        expect(app(CreateManualBooking::class)($data)->source)->toBe(BookingSource::Manual);
    });
})->group('fast');

it('records a discount in the snapshot beside the computed price', function (): void {
    $fixture = BookingApiScenario::bookable(unitPriceCents: 6500);

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        $booking = app(CreateManualBooking::class)(
            manualDraft($fixture),
            adjustment: new ManualBookingAdjustment(
                reason: 'Επαναλαμβανόμενος πελάτης',
                discountCents: 2000,
            ),
        );

        $adjustments = $booking->price_snapshot['operator_adjustments'] ?? null;

        // BKG-31: "both recorded in the price snapshot as operator adjustments"
        // — **not** as a silently different total. The engine's own figure
        // survives beside the operator's, because a total nobody can explain a
        // year later is worse than no discount at all.
        expect($booking->total_cents)->toBe(11000)
            ->and($adjustments)->not->toBeNull()
            ->and($adjustments['computed_total_cents'])->toBe(13000)
            ->and($adjustments['discount_cents'])->toBe(2000)
            ->and($adjustments['applied_total_cents'])->toBe(11000)
            ->and($adjustments['reason'])->toContain('πελάτης');
    });
})->group('fast');

it('records a total override as a different kind of adjustment', function (): void {
    $fixture = BookingApiScenario::bookable(unitPriceCents: 6500);

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        $booking = app(CreateManualBooking::class)(
            manualDraft($fixture),
            adjustment: new ManualBookingAdjustment(
                reason: 'Συμφωνήθηκε στρογγυλό ποσό στο τηλέφωνο',
                overrideTotalCents: 12000,
            ),
        );

        $adjustments = $booking->price_snapshot['operator_adjustments'];

        // A discount and an override mean different things to an accountant,
        // and collapsing them into one field would lose which happened.
        expect($booking->total_cents)->toBe(12000)
            ->and($adjustments['override_total_cents'])->toBe(12000)
            ->and($adjustments['discount_cents'])->toBe(0);
    });
})->group('fast');

it('refuses an adjustment with no reason', function (): void {
    // BKG-31's "with a reason", enforced by the constructor rather than by a
    // form — the third override in the product to work this way, after CXL-5's
    // refund and BKG-22's early check-in.
    expect(fn () => new ManualBookingAdjustment(reason: '  ', discountCents: 500))
        ->toThrow(InvalidArgumentException::class);
})->group('fast');

it('books a departure the website would no longer offer', function (): void {
    Carbon::setTestNow('2026-07-03 08:00:00');

    // Sailing in two hours. A rate plan with any lead time at all hides this
    // from the public calendar — and a walk-in an hour before departure is
    // exactly the case BKG-32's "may exceed" exists for.
    $fixture = BookingApiScenario::bookable(startsAt: Carbon::parse('2026-07-03 10:00:00'));

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        $booking = app(CreateManualBooking::class)(manualDraft($fixture, qty: 1));

        expect($booking->status)->toBe(BookingStatus::Draft)
            ->and($booking->hold_expires_at)->not->toBeNull();
    });
})->group('fast');

it('refuses to exceed the departure capacity without an override', function (): void {
    $fixture = BookingApiScenario::bookable(capacity: 2);

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        // BKG-32's "MUST NOT exceed capacity", with no confirmation given.
        expect(fn () => app(CreateManualBooking::class)(manualDraft($fixture, qty: 4)))
            ->toThrow(HoldRefused::class);
    });
})->group('fast');

it('exceeds the departure capacity with an explicit confirmation', function (): void {
    $fixture = BookingApiScenario::bookable(capacity: 2);

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        // The commercial ceiling, lifted. The vessel seats twenty; the operator
        // chose to sell two, and is deliberately selling a third.
        $booking = app(CreateManualBooking::class)(
            manualDraft($fixture, qty: 3),
            capacityOverrideReason: 'Οικογένεια τριών ατόμων, το σκάφος χωράει',
        );

        expect($booking->status)->toBe(BookingStatus::Draft)
            ->and($booking->pax_capacity_total)->toBe(3);
    });
})->group('fast');

it('logs the capacity override as an audit row with its numbers', function (): void {
    $fixture = BookingApiScenario::bookable(capacity: 2);

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        app(CreateManualBooking::class)(
            manualDraft($fixture, qty: 3),
            capacityOverrideReason: 'Οικογένεια τριών ατόμων, το σκάφος χωράει',
        );

        $row = AuditLog::query()->where('action', AuditAction::OverrideApplied->value)->sole();

        // BKG-32's "is logged", which is a row an operator can read six months
        // later — not a log line that has rotated away. The numbers travel with
        // the reason, because one over and six over are different decisions.
        expect($row->reason)->toContain('Οικογένεια')
            ->and($row->context['kind'])->toBe('capacity_override')
            ->and($row->context['seats_requested'])->toBe(3)
            ->and($row->context['capacity'])->toBe(2);
    });
})->group('fast');

it('never lets an override exceed the legal capacity', function (): void {
    // A vessel licensed for twenty is created by the fixture; a departure
    // selling two of them. Twenty-one people is not a commercial decision.
    $fixture = BookingApiScenario::bookable(capacity: 2);

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        try {
            app(CreateManualBooking::class)(
                manualDraft($fixture, qty: 21),
                capacityOverrideReason: 'Θα τους στριμώξουμε',
            );

            expect(false)->toBeTrue('A booking past capacity_max should have been refused.');
        } catch (HoldRefused $refused) {
            // AVL-25 is a certificate rather than a commercial number, and it
            // has no override at all. The infants a commercial capacity does
            // not count are exactly the ones a coastguard does.
            expect($refused->reason)->toBe('legal_capacity');
        }

        // And nothing was written: no audit row saying an override happened.
        expect(AuditLog::query()->where('action', AuditAction::OverrideApplied->value)->count())->toBe(0);
    });
})->group('fast');

it('marks a booking paid in cash and confirms it', function (): void {
    $fixture = BookingApiScenario::bookable(unitPriceCents: 6500);

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        $booking = app(CreateManualBooking::class)(
            manualDraft($fixture),
            paidBy: PaymentGatewayName::Cash,
        );

        $payment = Payment::query()->where('booking_id', $booking->getKey())->sole();

        // BKG-33: an ordinary `Payment` row whose gateway is the thing that
        // keeps it out of reconciliation — there is no third party to reconcile
        // against, because the money arrived at a desk.
        expect($booking->status)->toBe(BookingStatus::Confirmed)
            ->and($payment->gateway)->toBe(PaymentGatewayName::Cash)
            ->and($payment->gateway->isExternal())->toBeFalse()
            ->and($payment->amount_cents)->toBe(13000)
            // PAY-9's key is minted even here. It deduplicates nothing and
            // costs one uuid; a column that is sometimes null is a column every
            // later query has to special-case.
            ->and($payment->idempotency_key)->not->toBeNull();
    });
})->group('fast');

it('marks a booking paid by bank transfer', function (): void {
    $fixture = BookingApiScenario::bookable();

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        $booking = app(CreateManualBooking::class)(
            manualDraft($fixture),
            paidBy: PaymentGatewayName::BankTransfer,
        );

        // Two values rather than one `manual`, because an operator reconciling
        // their books wants to know which — and both answer BKG-33's actual
        // requirement, which is about calling nothing and reconciling nowhere.
        expect(Payment::query()->where('booking_id', $booking->getKey())->sole()->gateway)
            ->toBe(PaymentGatewayName::BankTransfer);
    });
})->group('fast');

it('commits the seats when a manual booking is paid', function (): void {
    $fixture = BookingApiScenario::bookable(capacity: 12);

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        app(CreateManualBooking::class)(manualDraft($fixture, qty: 3), paidBy: PaymentGatewayName::Cash);

        // A booking taken on the phone occupies seats exactly as a web booking
        // does. If it did not, the website would keep selling them.
        expect(Departure::query()->findOrFail($fixture['departure']->getKey())->seats_sold)->toBe(3);
    });
})->group('fast');
