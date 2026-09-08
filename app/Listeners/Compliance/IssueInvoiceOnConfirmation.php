<?php

declare(strict_types=1);

namespace App\Listeners\Compliance;

use App\Domain\Compliance\Actions\IssueInvoice;
use App\Events\BookingConfirmed;
use App\Jobs\SubmitInvoiceToMyData;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * BKG-13's step 4, which had no implementation until now (spec MYD-2, MYD-3.2,
 * MYD-3.3).
 *
 * `NotificationServiceProvider`'s table has said *"myDATA invoice — M6"* since
 * #88. This is it.
 *
 * ## The delay is the point, and it is fifteen minutes
 *
 * ADR-0003: the job runs `invoice_auto_issue_delay_minutes` after confirmation,
 * **not immediately**, so a guest has a window to say "I need a company invoice"
 * on the confirmation page. Issue instantly and every one of those becomes a
 * credit note plus a re-issue (MYD-3.7) — two extra documents in the operator's
 * series for a question answered ninety seconds late.
 *
 * The delay applies to the **submission**, not to writing the row. The invoice
 * exists at once, so an operator looking at a booking two minutes after it was
 * paid sees a document pending rather than nothing at all.
 *
 * ## Queued and independently retryable
 *
 * BKG-14: *"A failure in any listener MUST NOT roll back the confirmation or
 * block the others."* A guest's payment cannot be undone because a tax
 * authority is having an afternoon.
 *
 * ## Every reason not to issue is already inside `IssueInvoice`
 *
 * Test bookings (SAA-12), imports (BKG-34), operators who invoice through their
 * own software (MYD-4.5) and bookings that are not sales — all refused there,
 * with a sentence. This listener catches those refusals rather than duplicating
 * the conditions, because two copies of "may this be invoiced" is one copy too
 * many and the copy that goes stale is always the one in the listener.
 */
class IssueInvoiceOnConfirmation implements ShouldQueue
{
    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(BookingConfirmed $event): void
    {
        $tenant = Tenant::query()->find($event->tenantId);

        if (! $tenant instanceof Tenant) {
            return;
        }

        Tenancy::forTenant($tenant, function () use ($event, $tenant): void {
            $booking = Booking::query()->find($event->bookingId);

            if (! $booking instanceof Booking) {
                return;
            }

            // MYD-3.2's per-tenant switch. An operator who wants to issue by
            // hand turns it off and presses the button in the panel.
            if (! $tenant->invoice_auto_issue) {
                return;
            }

            try {
                $invoice = app(IssueInvoice::class)($booking);
            } catch (Throwable) {
                // A refusal from `IssueInvoice` is a decision, not a failure:
                // a test booking, an import, an operator on external invoicing.
                // Retrying would produce the same decision three times.
                return;
            }

            $this->submitAfterTheWindow($invoice, $tenant);
        });
    }

    /**
     * Queue the submission for the end of the tax-details window.
     *
     * Guarded on `hasNumber()` because a listener that runs twice — a queue
     * retry after a transient failure further down — must not dispatch a second
     * submission for a document already on its way.
     */
    private function submitAfterTheWindow(Invoice $invoice, Tenant $tenant): void
    {
        if ($invoice->hasNumber()) {
            return;
        }

        $delay = max(0, (int) $tenant->invoice_auto_issue_delay_minutes);

        SubmitInvoiceToMyData::dispatch((int) $invoice->tenant_id, (int) $invoice->getKey())
            ->delay(now()->addMinutes($delay));
    }
}
