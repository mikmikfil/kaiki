<?php

declare(strict_types=1);

use App\Contracts\MyDataGateway;
use App\Domain\Compliance\Actions\AllocateInvoiceNumber;
use App\Domain\Compliance\Actions\IssueInvoice;
use App\Domain\Compliance\Data\MyDataResult;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Jobs\SubmitInvoiceToMyData;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\InvoiceNumberGap;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Compliance\FakeMyDataGateway;

/*
|--------------------------------------------------------------------------
| Issuing and sending — spec MYD-2, MYD-5, MYD-7, MYD-10, MYD-14, SAA-12
|--------------------------------------------------------------------------
|
| Every one of these runs against `FakeMyDataGateway`, because the platform has
| no AADE credentials and will not have them for a while. That is not a
| compromise — it is the reason `MyDataGateway` is an interface. The behaviour
| under test is ours: what gets written, when a number is taken, what stops the
| retry ladder and what is recorded when it stops.
|
| The rule that carries the file is **MYD-4.2**: a number is allocated at the
| send attempt, so a document that is never submitted never burns one.
|
*/

/**
 * @param  array<string, mixed>  $bookingAttributes
 * @return array{0: Tenant, 1: Booking}
 */
function invoiceFixture(array $bookingAttributes = []): array
{
    $tenant = Tenant::factory()->create(['invoice_series' => 'A']);

    $booking = Tenancy::forTenant($tenant, fn (): Booking => Booking::factory()->create([
        'status' => BookingStatus::Confirmed,
        'total_cents' => 10_000,
        'vat_rate_bp' => 1_300,
        'price_snapshot' => [
            'version' => 1,
            'total_cents' => 10_000,
            // Net and VAT as the snapshot froze them. The halves sum to the
            // gross exactly, which is MYD-7 and which `IssueInvoice` asserts.
            'vat' => [
                'rate_bp' => 1_300,
                'vat_category' => 'VAT_2',
                'included' => true,
                'net_cents' => 8_850,
                'vat_cents' => 1_150,
            ],
        ],
        ...$bookingAttributes,
    ]));

    return [$tenant, $booking];
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-08 12:00:00');
});

it('writes a receipt with no number, ready to be sent', function (): void {
    [$tenant, $booking] = invoiceFixture();

    $invoice = Tenancy::forTenant($tenant, fn (): Invoice => app(IssueInvoice::class)($booking));

    expect($invoice->type)->toBe(InvoiceType::Alp)
        ->and($invoice->status)->toBe(InvoiceStatus::Pending)
        // MYD-4.2, the point of the whole design.
        ->and($invoice->number)->toBeNull()
        ->and($invoice->series)->toBe('A');
})->group('fast');

it('takes the money from the frozen snapshot rather than recomputing it', function (): void {
    // PRC-14 and ADR-0002: re-deriving would read today's `vat_rates` and could
    // rewrite last season's document after a statutory change.
    [$tenant, $booking] = invoiceFixture();

    $invoice = Tenancy::forTenant($tenant, fn (): Invoice => app(IssueInvoice::class)($booking));

    expect($invoice->net_cents)->toBe(8_850)
        ->and($invoice->vat_cents)->toBe(1_150)
        ->and($invoice->total_cents)->toBe(10_000)
        ->and($invoice->vat_rate_bp)->toBe(1_300)
        // Straight from the snapshot — CAT-11a forbids deriving a category from
        // a percentage anywhere in `app/`.
        ->and($invoice->vat_category)->toBe('VAT_2');
})->group('fast');

it('refuses a snapshot whose halves do not sum to its total', function (): void {
    // MYD-7. Loud here rather than silent at the tax office: AADE's refusal
    // would not say which of the three numbers is the wrong one.
    [$tenant, $booking] = invoiceFixture([
        'price_snapshot' => [
            'version' => 1,
            'total_cents' => 10_000,
            'vat' => ['rate_bp' => 1_300, 'vat_category' => 'VAT_2', 'net_cents' => 8_000, 'vat_cents' => 1_150],
        ],
    ]);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect(fn () => app(IssueInvoice::class)($booking))->toThrow(RuntimeException::class, 'MYD-7');
    });
})->group('fast');

it('issues an invoice with a counterparty when the guest gave valid tax details', function (): void {
    [$tenant, $booking] = invoiceFixture([
        'guest_vat_number' => 'EL 094 014 201',
        'guest_company_name' => 'Παράδειγμα ΑΕ',
        'guest_country' => 'GR',
    ]);

    $invoice = Tenancy::forTenant($tenant, fn (): Invoice => app(IssueInvoice::class)($booking));

    expect($invoice->type)->toBe(InvoiceType::Tpy)
        // Normalised, so AADE gets the nine digits rather than what was typed.
        ->and($invoice->counterparty_vat)->toBe('094014201')
        ->and($invoice->counterparty_name)->toBe('Παράδειγμα ΑΕ')
        ->and($invoice->counterparty_country)->toBe('GR');
})->group('fast');

it('puts no counterparty on a receipt', function (): void {
    // An ΑΛΠ is a retail receipt. Writing a private person's details onto one
    // would put an ΑΦΜ in a document that never needed it.
    [$tenant, $booking] = invoiceFixture(['guest_company_name' => 'Δεν είναι εταιρεία']);

    $invoice = Tenancy::forTenant($tenant, fn (): Invoice => app(IssueInvoice::class)($booking));

    expect($invoice->type)->toBe(InvoiceType::Alp)
        ->and($invoice->counterparty_vat)->toBeNull()
        ->and($invoice->counterparty_name)->toBeNull();
})->group('fast');

it('issues one document per booking however many times it is asked', function (): void {
    // MYD-3.6. The confirmation path, a queue retry and an operator pressing
    // the button all arrive at the same booking; a second ΑΛΠ for one sale is a
    // document that has to be undone by a third.
    [$tenant, $booking] = invoiceFixture();

    [$first, $second] = Tenancy::forTenant($tenant, function () use ($booking): array {
        $issue = app(IssueInvoice::class);

        return [$issue($booking), $issue($booking)];
    });

    expect($second->getKey())->toBe($first->getKey())
        ->and(Tenancy::forTenant($tenant, fn (): int => Invoice::query()->count()))->toBe(1);
})->group('fast');

it('never issues for a test booking', function (): void {
    // SAA-12. A sandbox sale registered with a real tax authority is a document
    // somebody has to cancel, and explain.
    [$tenant, $booking] = invoiceFixture(['is_test' => true]);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect(fn () => app(IssueInvoice::class)($booking))->toThrow(RuntimeException::class, 'SAA-12');
    });
})->group('fast');

it('never issues for an imported booking', function (): void {
    // BKG-34. The sale was invoiced where it happened; issuing again would
    // duplicate it in the operator's own series.
    [$tenant, $booking] = invoiceFixture(['source' => BookingSource::Import]);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect(fn () => app(IssueInvoice::class)($booking))->toThrow(RuntimeException::class, 'BKG-34');
    });
})->group('fast');

it('never issues for an operator who invoices through their own software', function (): void {
    // MYD-4.5. A second document against the same sale is a duplicate in their
    // register, and a booking engine that refuses to work alongside an
    // accountant's software is one the accountant tells them not to buy.
    $tenant = Tenant::factory()->create(['invoicing_mode' => 'external']);

    $booking = Tenancy::forTenant($tenant, fn (): Booking => Booking::factory()->create([
        'status' => BookingStatus::Confirmed,
        'total_cents' => 10_000,
        'price_snapshot' => ['vat' => ['net_cents' => 8_850, 'vat_cents' => 1_150]],
    ]));

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect(fn () => app(IssueInvoice::class)($booking))->toThrow(RuntimeException::class, 'MYD-4.5');
    });
})->group('fast');

it('never issues for a booking that is not a sale yet', function (): void {
    [$tenant, $booking] = invoiceFixture(['status' => BookingStatus::Draft]);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect(fn () => app(IssueInvoice::class)($booking))->toThrow(RuntimeException::class, 'nothing to invoice');
    });
})->group('fast');

it('does not talk to AADE from the issuing path', function (): void {
    // MYD-14: every myDATA call is queued. An operator pressing "issue" must
    // not wait on a tax authority, and a guest at checkout must not wait at all.
    Queue::fake();

    [$tenant, $booking] = invoiceFixture();
    $gateway = new FakeMyDataGateway;
    app()->instance(MyDataGateway::class, $gateway);

    Tenancy::forTenant($tenant, fn (): Invoice => app(IssueInvoice::class)($booking));

    expect($gateway->submissions)->toBe([]);
})->group('fast');

it('takes the number at the send attempt and marks the document registered', function (): void {
    [$tenant, $booking] = invoiceFixture();

    $gateway = (new FakeMyDataGateway)->willAnswer(
        MyDataResult::accepted('400000000000123', uid: 'UID-1', qrUrl: 'https://aade.example/qr/1'),
    );
    app()->instance(MyDataGateway::class, $gateway);

    $invoice = Tenancy::forTenant($tenant, function () use ($booking): Invoice {
        $invoice = app(IssueInvoice::class)($booking);

        (new SubmitInvoiceToMyData((int) $invoice->tenant_id, (int) $invoice->getKey()))
            ->handle($gateway = app(MyDataGateway::class), app(AllocateInvoiceNumber::class));

        return $invoice->refresh();
    });

    expect($invoice->status)->toBe(InvoiceStatus::Sent)
        ->and($invoice->mark)->toBe('400000000000123')
        ->and($invoice->number)->toBe(1)
        ->and($invoice->issued_at)->not->toBeNull()
        // The number was already on the row when the gateway saw it — MYD-4.2
        // allocates immediately before the call, not at dispatch.
        ->and($gateway->submissions[0]['number'])->toBe(1);
})->group('fast');

it('retries an unreachable AADE and gives up on a refusal', function (): void {
    // The distinction the whole result object exists for. A refusal will be
    // refused again in six hours; an outage may well accept the same payload.
    Queue::fake();

    [$tenant, $booking] = invoiceFixture();

    $gateway = (new FakeMyDataGateway)->willAnswer(MyDataResult::unreachable('timeout'));
    app()->instance(MyDataGateway::class, $gateway);

    $invoice = Tenancy::forTenant($tenant, function () use ($booking): Invoice {
        $invoice = app(IssueInvoice::class)($booking);

        (new SubmitInvoiceToMyData((int) $invoice->tenant_id, (int) $invoice->getKey()))
            ->handle(app(MyDataGateway::class), app(AllocateInvoiceNumber::class));

        return $invoice->refresh();
    });

    expect($invoice->status)->toBe(InvoiceStatus::Pending)
        ->and($invoice->retries)->toBe(1)
        ->and($invoice->next_retry_at)->not->toBeNull()
        // In Greek, computed now: the failure feed shows this string.
        ->and($invoice->last_error_message_el)->toContain('ΑΑΔΕ');

    Queue::assertPushed(SubmitInvoiceToMyData::class);
})->group('fast');

it('stops at once when AADE refuses, and records the number it burned', function (): void {
    // MYD-4.4. The number left the counter and no document carries it; an
    // unexplained gap in a Greek series is a question an operator cannot answer.
    Queue::fake();

    [$tenant, $booking] = invoiceFixture();

    $gateway = (new FakeMyDataGateway)->willAnswer(
        MyDataResult::refused('243', 'Counterparty VAT number not found'),
    );
    app()->instance(MyDataGateway::class, $gateway);

    [$invoice, $gap] = Tenancy::forTenant($tenant, function () use ($booking): array {
        $invoice = app(IssueInvoice::class)($booking);

        (new SubmitInvoiceToMyData((int) $invoice->tenant_id, (int) $invoice->getKey()))
            ->handle(app(MyDataGateway::class), app(AllocateInvoiceNumber::class));

        return [$invoice->refresh(), InvoiceNumberGap::query()->first()];
    });

    expect($invoice->status)->toBe(InvoiceStatus::Failed)
        ->and($invoice->retries)->toBe(1)
        ->and($invoice->next_retry_at)->toBeNull()
        // 243 is the one code the spec names, and it is mapped.
        ->and($invoice->last_error_message_el)->toContain('ΑΦΜ')
        ->and($gap?->number)->toBe(1)
        ->and($gap?->reason_code)->toBe(InvoiceNumberGap::REASON_PERMANENT_REJECTION);

    Queue::assertNotPushed(SubmitInvoiceToMyData::class);
})->group('fast');

it('refuses cleanly when myDATA was never connected', function (): void {
    // The null gateway, which is what is bound today. It must not report
    // success: an operator would otherwise accumulate invoices their books say
    // were filed and AADE has never heard of.
    Queue::fake();

    [$tenant, $booking] = invoiceFixture();

    $invoice = Tenancy::forTenant($tenant, function () use ($booking): Invoice {
        $invoice = app(IssueInvoice::class)($booking);

        (new SubmitInvoiceToMyData((int) $invoice->tenant_id, (int) $invoice->getKey()))
            ->handle(app(MyDataGateway::class), app(AllocateInvoiceNumber::class));

        return $invoice->refresh();
    });

    expect($invoice->status)->toBe(InvoiceStatus::Failed)
        ->and($invoice->mark)->toBeNull()
        ->and($invoice->last_error_message_el)->toContain('myDATA');

    Queue::assertNotPushed(SubmitInvoiceToMyData::class);
})->group('fast');

it('does not re-send a document AADE has already registered', function (): void {
    // A duplicate in a tax register, from a queue retry. The job checks the row
    // rather than trusting that it was only dispatched once.
    Queue::fake();

    [$tenant, $booking] = invoiceFixture();

    $gateway = new FakeMyDataGateway;
    app()->instance(MyDataGateway::class, $gateway);

    Tenancy::forTenant($tenant, function () use ($booking, $gateway): void {
        $invoice = app(IssueInvoice::class)($booking);
        $invoice->forceFill(['status' => InvoiceStatus::Sent, 'number' => 1, 'mark' => '400000000000999'])->save();

        (new SubmitInvoiceToMyData((int) $invoice->tenant_id, (int) $invoice->getKey()))
            ->handle($gateway, app(AllocateInvoiceNumber::class));
    });

    expect($gateway->submissions)->toBe([]);
})->group('fast');
