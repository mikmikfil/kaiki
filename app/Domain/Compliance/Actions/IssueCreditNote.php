<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Actions;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Jobs\SubmitInvoiceToMyData;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money\Cents;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The document that undoes a document (spec MYD-13, CXL-11).
 *
 * ## A refund does not delete an invoice; it answers one
 *
 * `docs/data-model.md` §1.4 puts invoices among the rows nothing removes, and a
 * document already registered with AADE cannot be withdrawn by deleting a row
 * in Kaiki. The register holds it. What a refund produces is a **second
 * document** pointing at the first, and `invoices.cancels_invoice_id` is the
 * pointer — MYD-13's *"linked to the original invoice"*.
 *
 * ## Partial refunds are the ordinary case, not the edge
 *
 * A weather cancellation under a policy that returns 60% is a partial refund,
 * and it is the most common refund this product will ever issue. So the amount
 * is a parameter and several credit notes may point at one invoice —
 * {@see Invoice::creditNotes()} is plural for that reason and
 * {@see IssueInvoice} deliberately exempts credit notes from its
 * one-per-booking rule.
 *
 * ## The VAT is split at the original's rate, not today's
 *
 * A credit note for €60 of a €100 sale at 13% carries €53.10 net and €6.90 VAT
 * — derived from **the invoice's** `vat_rate_bp`, which was itself snapshotted
 * at pricing time. Reading `vat_rates` here would apply a rate that may have
 * changed since, and a credit note that does not mirror its original is one an
 * accountant has to reconcile by hand.
 *
 * Taken as `total − net` rather than rounding both halves, which is the same
 * rule `ComputePrice` follows and the reason MYD-7's invariant holds without
 * anybody enforcing it twice.
 *
 * ## The original is closed by AADE accepting the credit note, not by writing it
 *
 * Two conditions, and the second was a bug a test found. A €40 credit against a
 * €100 sale leaves a €60 sale standing, so only a full credit closes the
 * original — and **a credit note AADE has not accepted has undone nothing at
 * all**.
 *
 * The first version marked the original `cancelled` here, at write time. A
 * refused credit note then left the sale looking cancelled in the operator's
 * books while the tax register still held it live, and — worse — the original
 * could never be credited again, because a cancelled invoice is not creditable.
 * The refund the operator still owed had no route to a document.
 *
 * So the close moved to {@see SubmitInvoiceToMyData}, where the MARK
 * arrives. The status now says what the register says, which is the only thing
 * it can usefully mean.
 */
final class IssueCreditNote
{
    /**
     * @param  int|null  $amountCents  null credits the whole invoice
     */
    public function __invoke(
        Invoice $original,
        ?int $amountCents = null,
        ?User $issuedBy = null,
    ): Invoice {
        $tenant = Tenancy::current();

        if (! $tenant instanceof Tenant) {
            throw new RuntimeException('A credit note needs a resolved tenant.');
        }

        $this->assertCreditable($original);

        $amount = $amountCents ?? $original->total_cents;

        $this->assertAmount($original, $amount);

        $booking = Booking::query()->findOrFail($original->booking_id);

        $credit = DB::transaction(function () use ($original, $amount, $booking, $issuedBy): Invoice {
            $net = Cents::netOfInclusive($amount, $original->vat_rate_bp);

            $credit = Invoice::query()->create([
                'booking_id' => $booking->getKey(),
                'type' => InvoiceType::Credit,
                'cancels_invoice_id' => $original->getKey(),
                'series' => $original->series,
                // MYD-4.2 again: a credit note is a document like any other and
                // takes its number at the send attempt.
                'number' => null,
                'year' => $original->year,
                'net_cents' => $net,
                // The remainder, never rounded separately — two independently
                // rounded halves can fail to sum to the total they came from.
                'vat_cents' => $amount - $net,
                'total_cents' => $amount,
                'vat_rate_bp' => $original->vat_rate_bp,
                'vat_category' => $original->vat_category,
                'counterparty_vat' => $original->counterparty_vat,
                'counterparty_name' => $original->counterparty_name,
                'counterparty_country' => $original->counterparty_country,
                'status' => InvoiceStatus::Pending,
                'environment' => $original->environment,
                'issued_by_user_id' => $issuedBy?->getKey(),
            ]);

            return $credit;
        });

        // MYD-13: *"issued through the same retry machinery"*. Not a second
        // sender with its own backoff — the ladder, the gap logging and the
        // Greek error dictionary are all one path.
        SubmitInvoiceToMyData::dispatch((int) $credit->tenant_id, (int) $credit->getKey());

        return $credit;
    }

    /**
     * Only a registered document can be undone.
     *
     * A `pending` invoice was never accepted by AADE, so there is nothing in the
     * register to answer — cancelling it is a status change, not a document. A
     * `failed` one likewise. And crediting a credit note is a shape nobody has a
     * name for.
     */
    private function assertCreditable(Invoice $original): void
    {
        if ($original->type->isCredit()) {
            throw new RuntimeException('A credit note cannot be credited (MYD-13).');
        }

        if ($original->status !== InvoiceStatus::Sent) {
            throw new RuntimeException(
                "Only a registered invoice can be credited; this one is {$original->status->value}.",
            );
        }
    }

    /**
     * The amount has to be positive and cannot exceed what is left.
     *
     * Refusing an over-credit is not pedantry: two partial credit notes totalling
     * more than the sale would leave an operator's register showing a negative
     * amount of trade, which is a correction their accountant makes by hand and
     * asks about at length.
     */
    private function assertAmount(Invoice $original, int $amount): void
    {
        if ($amount <= 0) {
            throw new RuntimeException('A credit note needs a positive amount.');
        }

        $alreadyCredited = $this->creditedSoFar($original);

        if ($alreadyCredited + $amount > $original->total_cents) {
            $remaining = $original->total_cents - $alreadyCredited;

            throw new RuntimeException(
                "Only {$remaining} cents remain to credit on {$original->reference()}; {$amount} was asked for.",
            );
        }
    }

    /** What earlier credit notes have already taken off this invoice. */
    private function creditedSoFar(Invoice $original): int
    {
        return (int) $original->creditNotes()
            // A credit note AADE refused took nothing off the sale, and counting
            // it would stop an operator re-issuing the refund they still owe.
            ->where('status', '!=', InvoiceStatus::Failed)
            ->sum('total_cents');
    }

    /**
     * Has everything been credited, counting only what AADE accepted?
     *
     * Public because the sender asks it when a MARK comes back — see the class
     * docblock on why the close lives there rather than here. `sent` alone, so
     * a pending sibling does not close a sale that is still standing.
     */
    public static function isFullyCredited(Invoice $original): bool
    {
        $credited = (int) $original->creditNotes()
            ->where('status', InvoiceStatus::Sent)
            ->sum('total_cents');

        return $credited >= $original->total_cents;
    }
}
