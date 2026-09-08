<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\BookingGuest;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Forget passport numbers on time (spec GDR-2, GDR-3, GDR-4, ADR-0012).
 *
 * ## The one job in this product whose success is that data is gone
 *
 * GDR-2 is a promise made to guests in the privacy notice and to operators in
 * the DPA: an identity document number is kept for a stated number of days after
 * the departure and then destroyed. A promise nothing enforces is a promise the
 * operator is breaking without knowing it — and unlike most broken promises,
 * this one accumulates silently and is discovered by a regulator.
 *
 * ## What it clears, and the three things it deliberately does not
 *
 * GDR-3.3 draws the line precisely: **`document_number` and `document_type`, and
 * nothing else**. The guest's **name, date of birth and nationality stay**, and
 * that is not an oversight:
 *
 * - the manifest a coastguard asked for last August has to remain explicable;
 * - a chargeback six months later is argued with a passenger list;
 * - and accounting retention is a different clock, stated separately in the
 *   privacy notice.
 *
 * Purging a name along with the number would leave an operator unable to answer
 * either question, in service of a promise nobody made.
 *
 * ## `document_purged_at` is why this is not simply "set it to null"
 *
 * A null `document_number` is ambiguous: it means *purged* or *never given*, and
 * those are opposite answers to "did this guest provide a document". The stamp
 * makes the difference legible, and {@see BookingGuest::hasDocument()} reads it
 * so that a purged row is not chased for missing details months later.
 *
 * ## Per tenant, and the window is each operator's own
 *
 * `tenants.guest_document_retention_days` — 30 to 365, default 90 (GDR-3.2) —
 * and the clock runs **from the departure**, not from the booking. A trip booked
 * in January for August is retained from August, which is what the notice says
 * and what an operator would expect.
 *
 * ## Idempotent, and the audit row carries no personal data
 *
 * GDR-4. Running it twice purges nothing the second time, because the query
 * already excludes rows with nothing left to clear. The log line carries a
 * tenant and a count — putting a name in the record of having deleted a name is
 * the joke that writes itself, and this job is where it would be written.
 */
class PurgeGuestDocumentsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** GDR-3.2's bounds, applied to whatever the tenant has stored. */
    public const MIN_DAYS = 30;

    public const MAX_DAYS = 365;

    public const DEFAULT_DAYS = 90;

    public function handle(): void
    {
        Tenant::query()->each(function (Tenant $tenant): void {
            Tenancy::forTenant($tenant, function () use ($tenant): void {
                $purged = $this->purgeFor($tenant);

                if ($purged === 0) {
                    return;
                }

                // GDR-4: counts and timestamps, never a name and never a number.
                Log::info('guest documents purged', [
                    'tenant_id' => $tenant->getKey(),
                    'purged' => $purged,
                    'retention_days' => self::retentionDays($tenant),
                ]);
            });
        });
    }

    /**
     * Clear every document older than this operator's window.
     *
     * Returns how many rows were cleared.
     */
    private function purgeFor(Tenant $tenant): int
    {
        $cutoff = Carbon::now($tenant->timezone)
            ->subDays(self::retentionDays($tenant))
            ->startOfDay()
            ->utc();

        // Chunked rather than one `update`, because `document_number` is an
        // `encrypted` cast: a mass update would write the literal string rather
        // than going through the cast, and the row would read back as a
        // decryption failure rather than as empty. The chunk keeps memory flat
        // on an operator with a long history.
        $cleared = 0;

        BookingGuest::query()
            ->whereNull('document_purged_at')
            ->whereNotNull('document_number')
            ->whereHas('booking.departure', fn ($query) => $query->where('ends_at_utc', '<', $cutoff))
            ->chunkById(200, function ($guests) use (&$cleared): void {
                foreach ($guests as $guest) {
                    DB::transaction(function () use ($guest, &$cleared): void {
                        $guest->forceFill([
                            'document_number' => null,
                            'document_type' => null,
                            'document_purged_at' => Carbon::now(),
                        ])->save();

                        $cleared++;
                    });
                }
            });

        return $cleared;
    }

    /**
     * This operator's window, clamped to GDR-3.2's bounds.
     *
     * Clamped rather than trusted: the column is validated at save, and a value
     * that arrived by a seeder, an import or a hand-edited row would otherwise
     * be able to set retention to zero days or to a decade. The bound that
     * matters is the floor — an operator cannot promise a guest thirty days and
     * quietly keep the number for one.
     */
    public static function retentionDays(Tenant $tenant): int
    {
        $days = (int) ($tenant->guest_document_retention_days ?: self::DEFAULT_DAYS);

        return max(self::MIN_DAYS, min(self::MAX_DAYS, $days));
    }
}
