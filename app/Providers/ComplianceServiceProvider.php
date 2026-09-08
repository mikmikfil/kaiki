<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\BookingConfirmed;
use App\Events\BookingRefunded;
use App\Listeners\Compliance\IssueCreditNoteOnRefund;
use App\Listeners\Compliance\IssueInvoiceOnConfirmation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Steps 4 of BKG-13 and CXL-11, wired (spec MYD-2, MYD-13).
 *
 * ## Why a provider of its own
 *
 * `NotificationServiceProvider`'s docblock carries BKG-13's nine confirmation
 * side effects as a table, and has said *"myDATA invoice — M6"* against step 4
 * since #88. That provider is about **messages** — its own docblock explains at
 * length why the email and the SMS are one listener — and putting a tax
 * authority into it would make the file about two unrelated things and give the
 * next person no idea where to look for either.
 *
 * ## Both listeners refuse quietly, and that is the design
 *
 * Neither one decides whether a document should exist; both ask something that
 * does. `IssueInvoice` refuses a test booking, an import, an operator on
 * external invoicing and a booking that is not a sale — each with a sentence —
 * and the confirmation listener catches those refusals rather than restating the
 * conditions. Two copies of "may this be invoiced" is one too many, and the copy
 * that goes stale is always the one in the listener.
 *
 * The refund listener does the same with "was there an invoice at all": most
 * refunds in this product's first year will be against bookings that were never
 * invoiced, and that is an ordinary outcome rather than an error.
 */
class ComplianceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // BKG-13.4 — queued, because `BookingConfirmed` carries scalars and a
        // guest's payment must not wait on a tax authority (MYD-14, BKG-14).
        Event::listen(BookingConfirmed::class, IssueInvoiceOnConfirmation::class);

        // CXL-11 — **not** queued: `BookingRefunded` carries the booking model
        // and ADR-0025 §2 keeps it inside the dispatching process. The listener
        // writes two rows and dispatches the submission as a job, so it returns
        // long before anything reaches AADE. See its docblock.
        Event::listen(BookingRefunded::class, IssueCreditNoteOnRefund::class);
    }
}
