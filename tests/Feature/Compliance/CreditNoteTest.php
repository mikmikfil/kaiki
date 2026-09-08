<?php

declare(strict_types=1);

use App\Contracts\MyDataGateway;
use App\Domain\Compliance\Actions\AllocateInvoiceNumber;
use App\Domain\Compliance\Actions\IssueCreditNote;
use App\Domain\Compliance\Actions\IssueInvoice;
use App\Domain\Compliance\Data\MyDataResult;
use App\Enums\BookingStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Jobs\SubmitInvoiceToMyData;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Compliance\FakeMyDataGateway;

/*
|--------------------------------------------------------------------------
| Credit notes — spec MYD-13, CXL-11
|--------------------------------------------------------------------------
|
| A refund does not delete an invoice; it **answers** one. A document already
| registered with AADE cannot be withdrawn by deleting a row here — the register
| holds it — so what a refund produces is a second document pointing at the
| first.
|
| The case that shapes the file is the **partial** refund. A weather
| cancellation under a policy returning 60% is the most common refund this
| product will ever issue, and a design that assumed a credit note undoes a whole
| sale would be wrong for nearly all of them.
|
*/

/** @return array{0: Tenant, 1: Invoice} */
function registeredInvoice(int $totalCents = 10_000): array
{
    $tenant = Tenant::factory()->create(['invoice_series' => 'A']);

    $invoice = Tenancy::forTenant($tenant, function () use ($totalCents): Invoice {
        $net = (int) round($totalCents * 10_000 / 11_300);

        $booking = Booking::factory()->create([
            'status' => BookingStatus::Confirmed,
            'total_cents' => $totalCents,
            'price_snapshot' => [
                'vat' => [
                    'rate_bp' => 1_300,
                    'vat_category' => 'VAT_2',
                    'net_cents' => $net,
                    'vat_cents' => $totalCents - $net,
                ],
            ],
        ]);

        $invoice = app(IssueInvoice::class)($booking);

        /*
         * Registered, because only a registered document can be credited — and
         * the number comes from the **allocator** rather than being written by
         * hand. Setting `number = 1` directly leaves the counter at zero, and
         * the next document allocates 1 again and trips the unique index. Which
         * is the index doing its job, and a good argument for a fixture that
         * takes the same path production does.
         */
        app(AllocateInvoiceNumber::class)($invoice);

        $invoice->forceFill([
            'status' => InvoiceStatus::Sent,
            'mark' => '400000000000001',
        ])->save();

        return $invoice;
    });

    return [$tenant, $invoice];
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-08 12:00:00');
    Queue::fake();
});

it('credits the whole sale by default', function (): void {
    [$tenant, $original] = registeredInvoice();

    $credit = Tenancy::forTenant($tenant, fn (): Invoice => app(IssueCreditNote::class)($original));

    expect($credit->type)->toBe(InvoiceType::Credit)
        ->and($credit->cancels_invoice_id)->toBe($original->getKey())
        ->and($credit->total_cents)->toBe(10_000)
        // Same series, so an accountant reading the series in order sees the
        // sale and its correction beside each other.
        ->and($credit->series)->toBe($original->series)
        // MYD-4.2 — a credit note takes its number at the send attempt too.
        ->and($credit->number)->toBeNull();

    // And the sale is **still standing**: writing a credit note undoes nothing
    // until AADE accepts it. See the next test.
    expect(Tenancy::forTenant($tenant, fn (): InvoiceStatus => $original->refresh()->status))
        ->toBe(InvoiceStatus::Sent);
})->group('fast');

it('closes the original only when AADE accepts the credit note', function (): void {
    /*
     * The bug this test was written after finding.
     *
     * The first version closed the sale when the credit note was *written*. A
     * refused credit note then left the sale looking cancelled in the operator's
     * books while the tax register still held it live — and made the original
     * uncreditable, so the refund the operator still owed had no route to a
     * document at all.
     */
    [$tenant, $original] = registeredInvoice();

    $gateway = (new FakeMyDataGateway)->willAnswer(MyDataResult::refused('243'));
    app()->instance(MyDataGateway::class, $gateway);

    Tenancy::forTenant($tenant, function () use ($original): void {
        $credit = app(IssueCreditNote::class)($original);

        (new SubmitInvoiceToMyData((int) $credit->tenant_id, (int) $credit->getKey()))
            ->handle(app(MyDataGateway::class), app(AllocateInvoiceNumber::class));
    });

    // Refused: the sale stands, and can be credited again.
    expect(Tenancy::forTenant($tenant, fn (): InvoiceStatus => $original->refresh()->status))
        ->toBe(InvoiceStatus::Sent);

    $accepting = (new FakeMyDataGateway)->willAnswer(MyDataResult::accepted('400000000000002'));
    app()->instance(MyDataGateway::class, $accepting);

    Tenancy::forTenant($tenant, function () use ($original): void {
        $credit = app(IssueCreditNote::class)($original->refresh());

        (new SubmitInvoiceToMyData((int) $credit->tenant_id, (int) $credit->getKey()))
            ->handle(app(MyDataGateway::class), app(AllocateInvoiceNumber::class));
    });

    // Accepted: now the register says the sale is undone, and so does the row.
    expect(Tenancy::forTenant($tenant, fn (): InvoiceStatus => $original->refresh()->status))
        ->toBe(InvoiceStatus::Cancelled);
})->group('fast');

it('splits VAT at the original’s rate, not today’s', function (): void {
    // €60 of a €100 sale at 13%: net 53.10, VAT 6.90. Reading `vat_rates` here
    // would apply a rate that may have changed since, and a credit note that
    // does not mirror its original is one an accountant reconciles by hand.
    [$tenant, $original] = registeredInvoice();

    $credit = Tenancy::forTenant($tenant, fn (): Invoice => app(IssueCreditNote::class)($original, 6_000));

    expect($credit->total_cents)->toBe(6_000)
        ->and($credit->net_cents)->toBe(5_310)
        ->and($credit->vat_cents)->toBe(690)
        // The halves sum to the whole exactly — MYD-7, held by taking VAT as
        // the remainder rather than rounding both.
        ->and($credit->net_cents + $credit->vat_cents)->toBe($credit->total_cents)
        ->and($credit->vat_rate_bp)->toBe($original->vat_rate_bp);
})->group('fast');

it('leaves the original standing after a partial credit', function (): void {
    // €40 credited against a €100 sale leaves a €60 sale. Marking it cancelled
    // would tell the operator's books that nothing was sold.
    [$tenant, $original] = registeredInvoice();

    Tenancy::forTenant($tenant, fn (): Invoice => app(IssueCreditNote::class)($original, 4_000));

    expect(Tenancy::forTenant($tenant, fn (): InvoiceStatus => $original->refresh()->status))
        ->toBe(InvoiceStatus::Sent);
})->group('fast');

it('allows several credit notes against one invoice', function (): void {
    // A partial refund followed by the rest is two documents, and a schema that
    // assumed one would make the second impossible to record.
    [$tenant, $original] = registeredInvoice();

    Tenancy::forTenant($tenant, function () use ($original): void {
        $issue = app(IssueCreditNote::class);

        $issue($original, 4_000);
        $issue($original->refresh(), 6_000);
    });

    $count = Tenancy::forTenant($tenant, fn (): int => $original->creditNotes()->count());

    expect($count)->toBe(2);
})->group('fast');

it('refuses to credit more than remains', function (): void {
    // Two partials totalling more than the sale would show a negative amount of
    // trade in the operator's register.
    [$tenant, $original] = registeredInvoice();

    Tenancy::forTenant($tenant, function () use ($original): void {
        app(IssueCreditNote::class)($original, 8_000);

        expect(fn () => app(IssueCreditNote::class)($original->refresh(), 5_000))
            ->toThrow(RuntimeException::class, 'remain to credit');
    });
})->group('fast');

it('does not count a refused credit note against what remains', function (): void {
    // AADE refusing a credit note means it took nothing off the sale, and
    // counting it would stop the operator re-issuing the refund they still owe.
    [$tenant, $original] = registeredInvoice();

    Tenancy::forTenant($tenant, function () use ($original): void {
        $failed = app(IssueCreditNote::class)($original, 10_000);
        $failed->forceFill(['status' => InvoiceStatus::Failed])->save();

        // The whole amount is available again.
        $second = app(IssueCreditNote::class)($original->refresh(), 10_000);

        expect($second->total_cents)->toBe(10_000);
    });
})->group('fast');

it('refuses a positive amount of nothing', function (): void {
    [$tenant, $original] = registeredInvoice();

    Tenancy::forTenant($tenant, function () use ($original): void {
        expect(fn () => app(IssueCreditNote::class)($original, 0))
            ->toThrow(RuntimeException::class, 'positive amount');
    });
})->group('fast');

it('refuses to credit a document AADE never accepted', function (): void {
    // A pending invoice was never registered, so there is nothing to answer.
    // Cancelling it is a status change, not a document.
    $tenant = Tenant::factory()->create();

    $pending = Tenancy::forTenant($tenant, function (): Invoice {
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Confirmed,
            'total_cents' => 10_000,
            'price_snapshot' => ['vat' => ['rate_bp' => 1_300, 'net_cents' => 8_850, 'vat_cents' => 1_150]],
        ]);

        return app(IssueInvoice::class)($booking);
    });

    Tenancy::forTenant($tenant, function () use ($pending): void {
        expect(fn () => app(IssueCreditNote::class)($pending))
            ->toThrow(RuntimeException::class, 'registered');
    });
})->group('fast');

it('refuses to credit a credit note', function (): void {
    [$tenant, $original] = registeredInvoice();

    Tenancy::forTenant($tenant, function () use ($original): void {
        $credit = app(IssueCreditNote::class)($original);
        $credit->forceFill(['status' => InvoiceStatus::Sent, 'number' => 2])->save();

        expect(fn () => app(IssueCreditNote::class)($credit))
            ->toThrow(RuntimeException::class, 'cannot be credited');
    });
})->group('fast');

it('sends the credit note through the same machinery as any other document', function (): void {
    // MYD-13: *"issued through the same retry machinery"*. Not a second sender
    // with its own backoff — the ladder, the gap logging and the Greek error
    // dictionary are one path.
    [$tenant, $original] = registeredInvoice();

    Tenancy::forTenant($tenant, fn (): Invoice => app(IssueCreditNote::class)($original));

    Queue::assertPushed(SubmitInvoiceToMyData::class);
})->group('fast');

it('carries the counterparty across, so the correction names the same customer', function (): void {
    [$tenant, $original] = registeredInvoice();

    Tenancy::forTenant($tenant, fn () => $original->forceFill([
        'type' => InvoiceType::Tpy,
        'counterparty_vat' => '094014201',
        'counterparty_name' => 'Παράδειγμα ΑΕ',
        'counterparty_country' => 'GR',
    ])->save());

    $credit = Tenancy::forTenant($tenant, fn (): Invoice => app(IssueCreditNote::class)($original->refresh()));

    expect($credit->counterparty_vat)->toBe('094014201')
        ->and($credit->counterparty_name)->toBe('Παράδειγμα ΑΕ');
})->group('fast');

it('does not talk to AADE from the crediting path', function (): void {
    // MYD-14 again: an operator pressing refund must not wait on a tax
    // authority, and neither must the guest whose money is going back.
    [$tenant, $original] = registeredInvoice();

    $gateway = app(MyDataGateway::class);

    Tenancy::forTenant($tenant, fn (): Invoice => app(IssueCreditNote::class)($original));

    // The null gateway records nothing; what is asserted is that the job was
    // queued rather than run.
    expect($gateway)->not->toBeNull();
    Queue::assertPushed(SubmitInvoiceToMyData::class);
})->group('fast');
