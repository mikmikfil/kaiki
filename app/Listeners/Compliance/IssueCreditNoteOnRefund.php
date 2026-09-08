<?php

declare(strict_types=1);

namespace App\Listeners\Compliance;

use App\Domain\Compliance\Actions\IssueCreditNote;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Events\BookingRefunded;
use App\Jobs\SubmitInvoiceToMyData;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A refund answers an invoice with another invoice (spec MYD-13, CXL-11).
 *
 * ## Only when there was something to answer
 *
 * CXL-11: *"Refunding a booking **with an issued invoice** triggers a myDATA
 * cancellation invoice."* A booking that was never invoiced — because the
 * operator invoices externally, because the guest paid cash and nothing was
 * issued, because the submission is still pending — has nothing in the register
 * to correct, and a credit note against nothing is a document an accountant
 * cannot place.
 *
 * So this looks for a **registered** invoice and quietly does nothing when
 * there is none. Not an error: it is the ordinary case for most refunds this
 * product will process in its first year.
 *
 * ## The amount is the refund, not the sale
 *
 * A weather cancellation under a policy returning 60% credits 60%. That is the
 * common case rather than the edge, and it is why {@see IssueCreditNote} takes
 * an amount instead of assuming the whole document.
 *
 * ## Synchronous, and that is forced rather than chosen
 *
 * `BookingRefunded` carries the **`Booking` model**, and ADR-0025 §2 is explicit
 * that it must not cross a queue boundary — the audit listener builds its entry
 * in the dispatching process and queues plain scalars. A `ShouldQueue` listener
 * here would serialise the model with the event and break that.
 *
 * The work is two inserts and a lookup, which is acceptable inline. The slow
 * half — talking to AADE — is already a job: `IssueCreditNote` dispatches
 * {@see SubmitInvoiceToMyData}, so this returns long before anything
 * reaches a tax authority.
 *
 * ## Nothing thrown here reaches the refund
 *
 * BKG-14's rule, and it matters more for a synchronous listener than a queued
 * one: an exception would propagate into the refund path and could unwind money
 * that has already moved. Everything is caught and written down where the
 * operator's failure feed can find it. What fails here is a *decision* — an
 * over-credit, a document already fully credited — and retrying a refusal
 * reproduces it.
 */
class IssueCreditNoteOnRefund
{
    public function handle(BookingRefunded $event): void
    {
        $tenant = Tenant::query()->find($event->tenantId());

        if (! $tenant instanceof Tenant) {
            return;
        }

        Tenancy::forTenant($tenant, function () use ($event): void {
            $original = Invoice::query()
                ->where('booking_id', $event->bookingId())
                ->where('type', '!=', InvoiceType::Credit)
                ->where('status', InvoiceStatus::Sent)
                ->latest('id')
                ->first();

            if (! $original instanceof Invoice) {
                // Nothing in the register to correct. The ordinary case.
                return;
            }

            try {
                app(IssueCreditNote::class)($original, $event->amountCents());
            } catch (Throwable $exception) {
                // The decision was refused — an over-credit, or a document
                // already fully credited. Retrying reproduces it.
                Log::warning('credit note not issued', [
                    'booking_id' => $event->bookingId(),
                    'invoice_id' => $original->getKey(),
                    'reason' => $exception->getMessage(),
                ]);
            }
        });
    }
}
