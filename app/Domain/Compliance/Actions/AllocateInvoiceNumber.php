<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Actions;

use App\Models\Invoice;
use App\Models\InvoiceNumberGap;
use App\Models\SeriesCounter;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Take the next number in an invoice series (spec MYD-4, ADR-0022 Option A).
 *
 * ## Two numbers the same is not a bug, it is an audit finding
 *
 * Everything about this class is shaped by that. An overbooked departure is
 * embarrassing and fixable; two documents sharing a number in a Greek invoicing
 * series is a question an operator answers to their accountant, and then
 * possibly to somebody else. So the guarantee is layered:
 *
 * 1. **[LOCK]** `lockForUpdate()` on the `series_counters` row for this
 *    (tenant, series, year), inside the transaction. Makes a collision rare.
 * 2. `unique(tenant_id, series, year, number)` on `invoices`. Makes it
 *    impossible.
 * 3. A retry when (2) fires anyway. Makes it invisible.
 *
 * The retry is not defensive coding for its own sake. **On SQLite the lock is a
 * no-op** (`docs/data-model.md` §0), so on every developer's machine layers 2
 * and 3 are the only ones running — which is a good argument for them being the
 * ones that actually hold, and for the concurrency test being MySQL-only and
 * skipping loudly rather than passing quietly.
 *
 * ## Allocated at the send attempt, not at row creation
 *
 * MYD-4.2. A document that is written and never submitted must not burn a
 * number. That is why this is a separate action called by the sender rather
 * than something `IssueInvoice` does while building the row: the moment of
 * allocation is a decision, and it has its own class so it cannot drift into
 * being "whenever we happened to save".
 *
 * ## The counter row is created on first use
 *
 * A missing row means the series has issued nothing this year, so the first
 * allocation creates it and returns 1. Nothing has to be seeded when the year
 * turns, and an operator who starts trading in July does not begin at a number
 * their books cannot explain.
 *
 * ## ⚠ The gap policy is not settled
 *
 * Spec §16.3 flags it for an accountant. **If gaps are ruled out, the change is
 * to the caller, not to this class**: allocate after AADE returns a MARK rather
 * than before the attempt. Nothing here assumes which side of that it is on.
 */
final class AllocateInvoiceNumber
{
    /**
     * How many times to re-take a number after a unique-index collision.
     *
     * Three, because a collision means another process took the number between
     * this one's read and its write — and if that happens three times in a row
     * under a lock, the lock is not working and a fourth attempt is not the
     * answer. The failure is loud so that a broken lock is discovered here
     * rather than in a numbering audit.
     */
    private const MAX_ATTEMPTS = 3;

    /**
     * Allocate and persist the next number for this invoice.
     *
     * Returns the number written. The invoice is saved with it; the caller is
     * expected to be inside the send path and to record a gap
     * ({@see InvoiceNumberGap}) if the send then fails permanently.
     */
    public function __invoke(Invoice $invoice, ?Carbon $now = null): int
    {
        if ($invoice->hasNumber()) {
            // Already allocated. Re-allocating on a retry would burn a second
            // number for one document, which is the exact failure this whole
            // class exists to make impossible.
            return (int) $invoice->number;
        }

        $tenant = Tenancy::current();

        if (! $tenant instanceof Tenant) {
            throw new RuntimeException('An invoice number needs a resolved tenant.');
        }

        $year = ($now ?? Carbon::now($tenant->timezone))->year;
        $series = (string) $invoice->series;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return $this->take($invoice, $tenant, $series, $year, $now);
            } catch (QueryException $exception) {
                if (! $this->isUniqueViolation($exception) || $attempt === self::MAX_ATTEMPTS) {
                    throw $exception;
                }
            }
        }

        // Unreachable: the loop either returns or rethrows on its last attempt.
        throw new RuntimeException('Invoice number allocation exhausted its attempts.');
    }

    private function take(Invoice $invoice, Tenant $tenant, string $series, int $year, ?Carbon $now): int
    {
        return DB::transaction(function () use ($invoice, $tenant, $series, $year, $now): int {
            $counter = SeriesCounter::query()
                ->where('series', $series)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if (! $counter instanceof SeriesCounter) {
                // First document of the year in this series. Created inside the
                // same transaction, so two processes arriving together resolve
                // through the unique index rather than both starting at 1.
                $counter = new SeriesCounter([
                    'tenant_id' => $tenant->getKey(),
                    'series' => $series,
                    'year' => $year,
                    'last_number' => 0,
                ]);
            }

            $number = $counter->last_number + 1;

            $counter->last_number = $number;
            $counter->last_allocated_at = $now ?? Carbon::now();
            $counter->save();

            $invoice->number = $number;
            $invoice->year = $year;
            $invoice->save();

            return $number;
        });
    }

    /**
     * Was this a unique-index collision rather than a real database problem?
     *
     * Matched on the driver's SQLSTATE rather than on message text: `23000` is
     * integrity-constraint violation in both SQLite and MySQL, and message
     * strings differ between them and between versions. A retry loop that keys
     * on English prose is a retry loop that stops working after an upgrade.
     */
    private function isUniqueViolation(QueryException $exception): bool
    {
        return $exception->getCode() === '23000';
    }
}
