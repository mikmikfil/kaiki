<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\ConfirmFromWebhook;
use App\Domain\Booking\Actions\ExpireQuotes;
use App\Domain\Booking\Actions\ExpireStaleHolds;
use App\Domain\Booking\Actions\ImportBooking;
use App\Domain\Booking\Actions\MintBalanceSession;
use App\Domain\Booking\Actions\RecordManualPayment;
use App\Domain\Booking\Actions\RefundBooking;
use App\Domain\Booking\Actions\RemoveGuestsFromBooking;
use App\Domain\Booking\Actions\SendQuote;
use App\Domain\Booking\Actions\StartCheckout;
use App\Domain\Booking\Data\BookingDraftData;
use App\Domain\Booking\Support\RefundEntitlement;
use App\Enums\BookingStatus;
use App\Enums\IntegrationProvider;
use App\Enums\PaymentGatewayName;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\IntegrationCredential;
use App\Models\Payment;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\Api\BookingApiScenario;
use Tests\Support\Booking\GuestPageScenario;
use Tests\Support\Booking\QuoteScenario;
use Tests\Support\Payments\WebhookScenario;

/*
|--------------------------------------------------------------------------
| Money that went nowhere, from the second audit (2026-09-25)
|--------------------------------------------------------------------------
|
| A balance order labelled with the deposit's cash gateway, money recorded
| against a quote, a part payment that never confirmed and a sweeper that
| expired it anyway, refunds counted twice or not at all, and a card page that
| charged what had already been paid.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    Carbon::setTestNow('2026-06-01 09:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** A succeeded incoming row, written the way the actions write them. */
function auditTwoCharge(Booking $booking, int $cents, PaymentGatewayName $gateway = PaymentGatewayName::Cash, PaymentKind $kind = PaymentKind::Balance): Payment
{
    return Payment::factory()->create([
        'booking_id' => $booking->getKey(),
        'gateway' => $gateway,
        'kind' => $kind,
        'amount_cents' => $cents,
    ]);
}

/** An open refund row with one of `RefundBooking`'s prefixes. */
function auditTwoOpenRefund(Payment $charge, int $cents, string $prefix): Payment
{
    return Payment::factory()->refundOf($charge, $cents)->create([
        'gateway' => $charge->gateway,
        'status' => PaymentStatus::Pending,
        'refunded_at' => null,
        'idempotency_key' => $prefix . str_replace('-', '', (string) Str::uuid()),
    ]);
}

it('labels a balance order Viva after a cash deposit, so its webhook finds it', function (): void {
    WebhookScenario::fakeGatewayResponses();

    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        IntegrationCredential::factory()->forProvider(IntegrationProvider::Viva)->live()->verified()->default()->create();

        $booking = Booking::factory()->create([
            'total_cents' => 12000,
            'deposit_cents' => 4000,
            'paid_cents' => 4000,
            'balance_cents' => 8000,
        ]);

        auditTwoCharge($booking, 4000, PaymentGatewayName::Cash, PaymentKind::Deposit);

        $target = app(MintBalanceSession::class)($booking);

        expect($target)->not->toBeNull();

        $row = Payment::findByGatewayRef(PaymentGatewayName::Viva, (string) $target?->reference);

        expect($row)->not->toBeNull()
            ->and($row?->kind)->toBe(PaymentKind::Balance)
            ->and($row?->gateway)->toBe(PaymentGatewayName::Viva);

        app(ConfirmFromWebhook::class)($row, true);

        expect($booking->refresh()->paid_cents)->toBe(12000)
            ->and($booking->balance_cents)->toBe(0)
            ->and($booking->balance_due_at)->toBeNull();
    });
})->group('fast');

it('records no money against a quote request or a sent quote', function (BookingStatus $status): void {
    [$tenant, $booking] = QuoteScenario::requested();

    Tenancy::forTenant($tenant, function () use ($booking, $status): void {
        $booking->forceFill(['status' => $status, 'total_cents' => 95000, 'balance_cents' => 95000])->save();

        expect(fn () => app(RecordManualPayment::class)($booking, 95000, PaymentGatewayName::BankTransfer))
            ->toThrow(ValidationException::class, __('bookings.payment.quote_first'));

        expect(Payment::query()->where('booking_id', $booking->getKey())->exists())->toBeFalse()
            ->and($booking->refresh()->status)->toBe($status)
            ->and(RecordManualPayment::refusalFor($booking))->toBe('bookings.payment.quote_first');
    });
})->with([
    'requested' => BookingStatus::QuoteRequested,
    'sent' => BookingStatus::QuoteSent,
])->group('fast');

it('confirms a held draft on a part payment, with the rest as a balance and a due date', function (): void {
    $fixture = BookingApiScenario::bookable(unitPriceCents: 6000);

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        $booking = Booking::factory()
            ->forDeparture($fixture['departure'])
            ->holding($fixture['departure'])
            ->withPax(2, 2)
            ->create(['total_cents' => 12000]);

        $fixture['departure']->forceFill(['seats_held' => 2])->save();

        app(RecordManualPayment::class)($booking, 5000, PaymentGatewayName::Cash);

        $booking->refresh();

        expect($booking->status)->toBe(BookingStatus::Confirmed)
            ->and($booking->paid_cents)->toBe(5000)
            ->and($booking->balance_cents)->toBe(7000)
            ->and($booking->balance_due_at)->not->toBeNull()
            ->and($fixture['departure']->refresh()->seats_sold)->toBe(2);
    });
})->group('fast');

it('refuses a payment by hand on a draft whose hold has lapsed', function (): void {
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        $booking = Booking::factory()->heldButExpired()->create();

        expect(fn () => app(RecordManualPayment::class)($booking, 5000, PaymentGatewayName::Cash))
            ->toThrow(ValidationException::class);

        expect(Payment::query()->where('booking_id', $booking->getKey())->exists())->toBeFalse();
    });
})->group('fast');

it('never expires a lapsed draft or a lapsed quote that has money on it', function (): void {
    $tenant = Tenant::factory()->create();

    $draft = Tenancy::forTenant($tenant, function (): Booking {
        $draft = Booking::factory()->heldButExpired()->create();

        auditTwoCharge($draft, 5000);

        return $draft;
    });

    app(ExpireStaleHolds::class)();

    Tenancy::forTenant($tenant, fn () => expect($draft->refresh()->status)->toBe(BookingStatus::Draft));

    [$quoteTenant, $booking, $quote] = QuoteScenario::drafted(charterCents: 95000);

    Tenancy::forTenant($quoteTenant, function () use ($booking, $quote): void {
        app(SendQuote::class)($quote);

        auditTwoCharge($booking->refresh(), 20000, PaymentGatewayName::BankTransfer);

        $quote->refresh()->forceFill(['valid_until' => now()->subMinute()])->save();
    });

    app(ExpireQuotes::class)();

    Tenancy::forTenant($quoteTenant, fn () => expect($booking->refresh()->status)->not->toBe(BookingStatus::Expired));
})->group('fast');

it('confirms, rather than reverts, a checkout with money on it when its card fails', function (): void {
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        $booking = Booking::factory()->pendingPayment()->create(['total_cents' => 12000]);

        auditTwoCharge($booking, 4000);

        $card = Payment::factory()->pending()->create([
            'booking_id' => $booking->getKey(),
            'amount_cents' => 12000,
            'gateway_ref' => 'order-1',
        ]);

        app(ConfirmFromWebhook::class)($card, false);

        $booking->refresh();

        expect($booking->status)->toBe(BookingStatus::Confirmed)
            ->and($booking->paid_cents)->toBe(4000)
            ->and($booking->balance_cents)->toBe(8000)
            ->and($card->refresh()->status)->toBe(PaymentStatus::Failed);
    });
})->group('fast');

it('gives an imported booking with an open balance a due date', function (): void {
    $fixture = BookingApiScenario::bookable();

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        $booking = app(ImportBooking::class)(
            new BookingDraftData(
                product: $fixture['product'],
                date: $fixture['departure']->local_date->copy(),
                guestName: 'Ελένη Δημητρίου',
                guestEmail: 'eleni@example.gr',
                paxByCode: ['adult' => 2],
            ),
            totalCents: 50000,
            paidCents: 15000,
            departure: $fixture['departure'],
        );

        expect($booking->refresh()->balance_cents)->toBe(35000)
            ->and($booking->balance_due_at)->not->toBeNull();
    });
})->group('fast');

it('takes a cancellation\'s percentage on money not already on its way back', function (): void {
    Queue::fake();

    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 20000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $charge = Payment::query()->where('booking_id', $booking->getKey())->sole();

        // Two people taken off: €100 of it on its way back, still pending.
        $removed = auditTwoOpenRefund($charge, 10000, RefundBooking::PARTIAL_KEY_PREFIX);

        $entitlement = RefundEntitlement::atPercent($booking->refresh(), 50);

        expect($entitlement->totalCents)->toBe(5000);

        app(RefundBooking::class)($booking, $entitlement);

        // €100 for the people removed, and half of the €100 left.
        expect($removed->refresh()->amount_cents)->toBe(10000)
            ->and((int) Payment::query()
                ->where('booking_id', $booking->getKey())
                ->where('kind', PaymentKind::Refund->value)
                ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Processing->value])
                ->sum('amount_cents'))->toBe(15000);
    });
})->group('fast');

it('leaves a late surplus out of a cancellation\'s base', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 20000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        // Paid twice; the second charge is on its way back.
        $second = auditTwoCharge($booking, 20000, PaymentGatewayName::Viva, PaymentKind::Full);
        auditTwoOpenRefund($second, 20000, RefundBooking::LATE_KEY_PREFIX);

        $booking->forceFill(['paid_cents' => 40000])->save();

        expect(RefundEntitlement::atPercent($booking, 50)->totalCents)->toBe(10000);
    });
})->group('fast');

it('writes a second partial refund while the first is still open', function (): void {
    Queue::fake();

    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 20000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $charge = Payment::query()->where('booking_id', $booking->getKey())->sole();

        auditTwoOpenRefund($charge, 5000, RefundBooking::PARTIAL_KEY_PREFIX);

        expect(app(RefundBooking::class)->partial($booking, 3000))->toBe(3000)
            ->and(Payment::query()->where('kind', PaymentKind::Refund->value)->count())->toBe(2);

        // Never more than is still held: €200 paid, €80 already going back.
        expect(app(RefundBooking::class)->partial($booking, 12001))->toBe(0);
    });
})->group('fast');

it('previews a removal net of refunds already on their way', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 20000, pax: 4);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $booking->forceFill([
            'pax_breakdown' => [['code' => 'adult', 'qty' => 4, 'unit_price_cents' => 5000, 'counts_toward_capacity' => true]],
        ])->save();

        $charge = Payment::query()->where('booking_id', $booking->getKey())->sole();

        // One person was taken off earlier and the cash is still to go back.
        auditTwoOpenRefund($charge, 5000, RefundBooking::PARTIAL_KEY_PREFIX);

        $preview = RemoveGuestsFromBooking::preview($booking->refresh(), ['adult' => 1]);

        expect($preview['new_total_cents'])->toBe(15000)
            // €200 held less €50 going back is €150: nothing more is owed.
            ->and($preview['refund_cents'])->toBe(0);
    });
})->group('fast');

it('charges a returning checkout only what is not already paid', function (): void {
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        $booking = Booking::factory()->pendingPayment()->create(['total_cents' => 12000]);

        auditTwoCharge($booking, 4000);

        $result = app(StartCheckout::class)($booking);

        expect($result['payment']?->amount_cents)->toBe(8000)
            ->and($result['payment']?->kind)->toBe(PaymentKind::Full);
    });
})->group('fast');

it('confirms a returning checkout whose deposit is already paid, with no card page', function (): void {
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        $booking = Booking::factory()->pendingPayment()->create(['total_cents' => 12000, 'deposit_cents' => 4000]);

        auditTwoCharge($booking, 4000);

        $result = app(StartCheckout::class)($booking);

        expect($result['payment'])->toBeNull()
            ->and($result['booking']->status)->toBe(BookingStatus::Confirmed)
            ->and($result['booking']->balance_cents)->toBe(8000);
    });
})->group('fast');
