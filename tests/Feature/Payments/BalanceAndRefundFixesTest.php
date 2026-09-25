<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\RecordManualPayment;
use App\Domain\Booking\Actions\RefundBooking;
use App\Domain\Compliance\Actions\AllocateInvoiceNumber;
use App\Domain\Compliance\Actions\IssueInvoice;
use App\Domain\Operations\Support\AttentionItems;
use App\Domain\Payments\Support\GatewayResolver;
use App\Enums\BookingStatus;
use App\Enums\IntegrationProvider;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\PaymentGatewayName;
use App\Enums\PaymentStatus;
use App\Jobs\ExecuteGatewayRefund;
use App\Models\Booking;
use App\Models\IntegrationCredential;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Support\Tenancy;
use Brick\Money\Money;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\postJson;

use Tests\Support\Booking\GuestPageScenario;
use Tests\Support\Payments\WebhookScenario;

/*
|--------------------------------------------------------------------------
| Balances that outlive the boat, and refunds that reach the right charge
|--------------------------------------------------------------------------
|
| 2026-09-25, from the deposits plan (Α6, Α7) and the logic audit:
|
| - an overdue balance stays in «Χρειάζεται προσοχή» after the boat sails;
| - money arriving after the due date does not move the due date;
| - a Viva refund reverses the **transaction**, never the order;
| - money given back because it was paid twice is not credited against the
|   invoice, which never counted it.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-06-20 09:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @return list<string> */
function owedKeys(): array
{
    return array_map(
        static fn ($item): string => $item->key,
        (new AttentionItems('Europe/Athens'))->everything(),
    );
}

it('keeps an overdue balance on the attention list after the boat sails', function (BookingStatus $status): void {
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 4000, balanceCents: 8000);

    Tenancy::forTenant($tenant, function () use ($booking, $status): void {
        $booking->forceFill([
            'status' => $status,
            'is_test' => false,
            'balance_due_at' => now()->subDays(3),
        ])->save();

        expect(owedKeys())->toContain('balance:' . $booking->getKey());

        app(RecordManualPayment::class)($booking, 8000, PaymentGatewayName::Cash);

        expect(owedKeys())->not->toContain('balance:' . $booking->getKey());
    });
})->with([
    'checked in' => BookingStatus::CheckedIn,
    'completed' => BookingStatus::Completed,
])->group('fast');

it('does not move the due date when part of an overdue balance is paid', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 4000, balanceCents: 8000);

    $due = Carbon::parse('2026-06-18 06:00:00');

    Tenancy::forTenant($tenant, function () use ($booking, $due): void {
        $booking->forceFill(['balance_due_at' => $due, 'is_test' => false])->save();

        app(RecordManualPayment::class)($booking, 3000, PaymentGatewayName::Cash);

        $booking->refresh();

        expect($booking->balance_cents)->toBe(5000)
            ->and($booking->balance_due_at?->equalTo($due))->toBeTrue()
            ->and(owedKeys())->toContain('balance:' . $booking->getKey());

        // Paid off: the date goes, as before.
        app(RecordManualPayment::class)($booking, 5000, PaymentGatewayName::Cash);

        expect($booking->refresh()->balance_due_at)->toBeNull();
    });
})->group('fast');

it('stores the transaction id from the success webhook', function (): void {
    config(['queue.default' => 'sync']);
    WebhookScenario::fakeGatewayResponses();

    [$tenant, , $payment] = WebhookScenario::make();

    $payload = WebhookScenario::gatewaySuccess('b1f9c0de-0000-4000-8000-00000000abcd');

    postJson('/webhooks/viva', $payload, WebhookScenario::verifiedHeaders($payload))->assertOk();

    Tenancy::forTenant($tenant, function () use ($payment): void {
        $payment->refresh();

        expect($payment->status)->toBe(PaymentStatus::Succeeded)
            ->and($payment->gateway_transaction_ref)->toBe('b1f9c0de-0000-4000-8000-00000000abcd')
            // The order code the payment was found by stays as it was.
            ->and($payment->gateway_ref)->toBe(WebhookScenario::REFERENCE);
    });
})->group('fast');

/**
 * A Viva charge and the gateway, with the credential Viva refunds through.
 *
 * @return array{0: Tenant, 1: Payment}
 */
function vivaCharge(?string $transactionRef): array
{
    $tenant = Tenant::factory()->create();

    $payment = Tenancy::forTenant($tenant, static function () use ($transactionRef): Payment {
        IntegrationCredential::factory()
            ->forProvider(IntegrationProvider::Viva)
            ->live()->verified()->default()->create();

        $booking = Booking::factory()->create(['is_test' => false, 'total_cents' => 12000]);

        return Payment::factory()->create([
            'booking_id' => $booking->getKey(),
            'gateway' => PaymentGatewayName::Viva,
            'amount_cents' => 12000,
            'status' => PaymentStatus::Succeeded,
            'gateway_ref' => '7310123456789012',
            'gateway_transaction_ref' => $transactionRef,
        ]);
    });

    return [$tenant, $payment];
}

it('refunds a Viva charge by its transaction id, never its order code', function (): void {
    Http::fake([
        '*accounts*/connect/token' => Http::response(['access_token' => 'tok_test', 'expires_in' => 3600]),
        '*/api/transactions/*' => Http::response(['TransactionId' => 'refund-tx']),
    ]);

    [$tenant, $payment] = vivaCharge('b1f9c0de-0000-4000-8000-00000000abcd');

    $result = Tenancy::forTenant($tenant, fn () => app(GatewayResolver::class)
        ->named(PaymentGatewayName::Viva)
        ->refund($payment, Money::ofMinor(5000, 'EUR')));

    expect($result->succeeded)->toBeTrue();

    Http::assertSent(static fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/api/transactions/b1f9c0de-0000-4000-8000-00000000abcd?amount=5000'));
    Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '7310123456789012'));
})->group('fast');

it('looks the transaction up by its order for a charge confirmed before the id was kept', function (): void {
    Http::fake([
        '*accounts*/connect/token' => Http::response(['access_token' => 'tok_test', 'expires_in' => 3600]),
        '*/api/transactions?*' => Http::response(['Transactions' => [
            ['StatusId' => 'E', 'Amount' => 120.00, 'TransactionId' => 'declined-tx'],
            ['StatusId' => 'F', 'Amount' => 120.00, 'TransactionId' => 'paid-tx'],
        ]]),
        '*/api/transactions/*' => Http::response(['TransactionId' => 'refund-tx']),
    ]);

    [$tenant, $payment] = vivaCharge(null);

    $result = Tenancy::forTenant($tenant, fn () => app(GatewayResolver::class)
        ->named(PaymentGatewayName::Viva)
        ->refund($payment, Money::ofMinor(12000, 'EUR')));

    expect($result->succeeded)->toBeTrue();

    Http::assertSent(static fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/api/transactions/paid-tx?amount=12000'));
})->group('fast');

/**
 * A booking with a registered invoice for its whole total, a card charge
 * behind it, and a late refund row against that charge.
 *
 * @return array{0: Tenant, 1: Booking, 2: Payment}
 */
function invoicedWithLateRefund(BookingStatus $status): array
{
    $tenant = Tenant::factory()->create(['invoice_series' => 'A']);

    [$booking, $refund] = Tenancy::forTenant($tenant, static function () use ($status): array {
        IntegrationCredential::factory()
            ->forProvider(IntegrationProvider::Viva)
            ->live()->verified()->default()->create();

        $booking = Booking::factory()->create([
            'status' => BookingStatus::Confirmed,
            'is_test' => false,
            'total_cents' => 11300,
            'paid_cents' => 11300,
            'balance_cents' => 0,
            'price_snapshot' => [
                'vat' => ['rate_bp' => 1300, 'vat_category' => 'VAT_2', 'net_cents' => 10000, 'vat_cents' => 1300],
            ],
        ]);

        $invoice = app(IssueInvoice::class)($booking);
        app(AllocateInvoiceNumber::class)($invoice);
        $invoice->forceFill(['status' => InvoiceStatus::Sent, 'mark' => '400000000000001'])->save();

        // What the invoice was for, paid.
        Payment::factory()->create([
            'booking_id' => $booking->getKey(),
            'gateway' => PaymentGatewayName::Viva,
            'amount_cents' => 11300,
            'status' => PaymentStatus::Succeeded,
            'gateway_ref' => '7310000000000000',
            'gateway_transaction_ref' => 'charge-tx',
        ]);

        // The charge the late money came in on.
        $charge = Payment::factory()->create([
            'booking_id' => $booking->getKey(),
            'gateway' => PaymentGatewayName::Viva,
            'amount_cents' => 5000,
            'status' => PaymentStatus::Succeeded,
            'gateway_ref' => '7310000000000001',
            'gateway_transaction_ref' => 'late-charge-tx',
        ]);

        $booking->forceFill(['status' => $status])->save();

        $refund = app(RefundBooking::class)->lateRefundRow($booking, $charge, 5000);

        return [$booking, $refund];
    });

    return [$tenant, $booking, $refund];
}

function creditNotes(Tenant $tenant, Booking $booking): int
{
    return Tenancy::forTenant($tenant, static fn (): int => Invoice::query()
        ->where('booking_id', $booking->getKey())
        ->where('type', InvoiceType::Credit->value)
        ->count());
}

it('issues no credit note for money paid on top of the total and given back', function (): void {
    Queue::fake();
    Http::fake([
        '*accounts*/connect/token' => Http::response(['access_token' => 'tok_test', 'expires_in' => 3600]),
        '*/api/transactions/*' => Http::response(['TransactionId' => 'refund-tx']),
    ]);

    [$tenant, $booking, $refund] = invoicedWithLateRefund(BookingStatus::Confirmed);

    (new ExecuteGatewayRefund((int) $refund->getKey()))->handle(app(GatewayResolver::class));

    expect(Tenancy::forTenant($tenant, fn () => $refund->refresh()->status))->toBe(PaymentStatus::Succeeded)
        ->and(creditNotes($tenant, $booking))->toBe(0);
})->group('fast');

it('still credits the invoice for a late payment given back on a cancelled booking', function (): void {
    // The invoice was issued for the whole total, this money included.
    Queue::fake();
    Http::fake([
        '*accounts*/connect/token' => Http::response(['access_token' => 'tok_test', 'expires_in' => 3600]),
        '*/api/transactions/*' => Http::response(['TransactionId' => 'refund-tx']),
    ]);

    [$tenant, $booking, $refund] = invoicedWithLateRefund(BookingStatus::Cancelled);

    (new ExecuteGatewayRefund((int) $refund->getKey()))->handle(app(GatewayResolver::class));

    expect(creditNotes($tenant, $booking))->toBe(1);
})->group('fast');
