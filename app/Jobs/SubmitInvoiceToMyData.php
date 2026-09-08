<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\MyDataGateway;
use App\Domain\Compliance\Actions\AllocateInvoiceNumber;
use App\Domain\Compliance\Data\MyDataResult;
use App\Domain\Compliance\Support\AadeErrors;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceNumberGap;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * One attempt at AADE, and the decision about whether there will be another
 * (spec MYD-5, MYD-10, MYD-14, MYD-15, MYD-4.4).
 *
 * ## The number is taken here, and that is the whole reason this job exists
 *
 * MYD-4.2: allocation happens at the **send attempt**, so a document that is
 * written and never submitted does not burn a number. `IssueInvoice` writes the
 * row with `number = null`; this is the first code that gives it one, and it
 * does so immediately before the call rather than at dispatch — a job sitting in
 * a queue for an hour must not be holding a number.
 *
 * ## The retry schedule is ours, not the queue's
 *
 * The same argument as {@see DeliverWebhook}. MYD-10 publishes it — eight
 * attempts over roughly a day — and the panel has to show an operator which
 * attempt a document is on and when the next one is due, which a queue's
 * internal retry state cannot answer. So the job never throws to signal a
 * retry: it records the attempt, sets `next_retry_at`, and re-dispatches itself
 * with a delay.
 *
 * ## Refused and unreachable are different, and conflating them is the bug
 *
 * {@see MyDataResult} separates them and this acts on the difference. A refusal
 * — a malformed ΑΦΜ, a category that does not exist — will be refused again in
 * six hours, so retrying it is eight attempts at something that was never going
 * to work, filling the operator's failure feed with noise. An unreachable
 * endpoint may well accept the identical payload on the next try.
 *
 * ## A refusal after allocation writes a gap
 *
 * MYD-4.4. The number left the counter and no document carries it, and an
 * unexplained gap in a Greek series is a question an operator cannot answer.
 * The row records the number, the reason and the moment.
 *
 * **⚠ If the accountant rules gaps out** (spec §16.3), the change is here and
 * only here: allocate after a MARK comes back rather than before the call. The
 * allocator does not care which side it is on.
 */
class SubmitInvoiceToMyData implements ShouldQueue
{
    use Queueable;

    /**
     * MYD-10's ladder: eight attempts over roughly twenty-four hours.
     *
     * A published schedule, so it lives where a person can read it rather than
     * inside a `backoff()` the panel cannot see.
     */
    public const BACKOFF_SECONDS = [30, 120, 600, 1_800, 3_600, 7_200, 21_600, 43_200];

    /** One attempt per job — the ladder is the row's, not the queue's. */
    public int $tries = 1;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $invoiceId,
    ) {}

    public function handle(MyDataGateway $gateway, AllocateInvoiceNumber $allocate): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if (! $tenant instanceof Tenant) {
            // The operator was deleted while this sat in the queue. Nothing to
            // send and nobody to tell.
            return;
        }

        Tenancy::forTenant($tenant, function () use ($gateway, $allocate): void {
            $invoice = Invoice::query()->find($this->invoiceId);

            if (! $invoice instanceof Invoice || $invoice->status !== InvoiceStatus::Pending) {
                // Already sent, already given up on, or cancelled by an operator
                // between dispatch and now. Re-sending a registered document
                // would duplicate it in a tax register.
                return;
            }

            // MYD-4.2 — here, not at dispatch. Idempotent, so a second run of a
            // job that crashed after allocating does not take a second number.
            $number = $allocate($invoice);

            $result = $gateway->submit($invoice);

            $result->accepted
                ? $this->recordAcceptance($invoice, $result)
                : $this->recordFailure($invoice, $result, $number);
        });
    }

    /**
     * MYD-5: a MARK, and only a MARK, moves this to `sent`.
     */
    private function recordAcceptance(Invoice $invoice, MyDataResult $result): void
    {
        $invoice->forceFill([
            'status' => InvoiceStatus::Sent,
            'mark' => $result->mark,
            'uid' => $result->uid,
            'authentication_code' => $result->authenticationCode,
            'qr_url' => $result->qrUrl,
            'issued_at' => Carbon::now(),
            'next_retry_at' => null,
            'last_error_code' => null,
            'last_error_message' => null,
            'last_error_message_el' => null,
            'response_payload' => $result->rawResponse,
        ])->save();
    }

    private function recordFailure(Invoice $invoice, MyDataResult $result, int $number): void
    {
        $attempt = $invoice->retries + 1;
        $retryable = AadeErrors::isRetryable($result) && $attempt < count(self::BACKOFF_SECONDS);

        $invoice->forceFill([
            'status' => $retryable ? InvoiceStatus::Pending : InvoiceStatus::Failed,
            'retries' => $attempt,
            'last_error_code' => $result->errorCode,
            'last_error_message' => $result->errorMessage,
            // Greek, pinned rather than taken from the worker's locale — the
            // column says `_el` and a queue worker holds whatever the last job
            // on it set. See `AadeErrors::inGreek()`.
            'last_error_message_el' => AadeErrors::inGreek($result->errorCode, $result->errorMessage),
            'next_retry_at' => $retryable ? $this->nextAttemptAt($attempt) : null,
            'response_payload' => $result->rawResponse,
        ])->save();

        if ($retryable) {
            self::dispatch($this->tenantId, $this->invoiceId)
                ->delay($this->nextAttemptAt($attempt));

            return;
        }

        // MYD-4.4 — the number is spent and no document carries it.
        InvoiceNumberGap::query()->create([
            'series' => $invoice->series,
            'year' => $invoice->year,
            'number' => $number,
            'invoice_id' => $invoice->getKey(),
            'reason_code' => $result->retryable
                ? InvoiceNumberGap::REASON_RETRIES_EXHAUSTED
                : InvoiceNumberGap::REASON_PERMANENT_REJECTION,
            'reason_detail' => $result->errorCode,
        ]);

        // MYD-15: the code and our own Greek line, never the payload and never
        // a credential. The raw AADE message is on the row for support.
        Log::warning('myDATA issuance gave up', [
            'invoice_id' => $invoice->getKey(),
            'series' => $invoice->series,
            'number' => $number,
            'error_code' => $result->errorCode,
            'attempts' => $attempt,
        ]);
    }

    private function nextAttemptAt(int $attempt): Carbon
    {
        $ladder = self::BACKOFF_SECONDS;

        // Clamped to the last rung rather than falling off the end. `handle()`
        // stops retrying at the ladder's length anyway, so this only guards a
        // caller that reached past it — and twelve hours is the right answer to
        // "later than the last one" in every reading.
        $seconds = $ladder[$attempt - 1] ?? $ladder[count($ladder) - 1];

        return Carbon::now()->addSeconds($seconds);
    }
}
