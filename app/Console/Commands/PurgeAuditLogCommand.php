<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Deletes audit rows past the retention window (ADR-0025 §3).
 *
 * ## Seven years, and the number is a decision rather than a default
 *
 * `config('kaiki.audit.retention_days')` is 2555. ADR-0012 purges personal data
 * at ninety days; this table is the documented exception, and the exception is
 * paid for by the actor being a `user_id` rather than a name — see the config
 * block for the full reasoning.
 *
 * ## Platform-wide, and it says so
 *
 * The only caller of {@see AuditLog::purge()}, which suspends tenancy on
 * purpose. Retention is an obligation the platform owes, not a setting an
 * operator chooses, so running it per tenant would be both slower and wrong:
 * a tenant that had been suspended would keep rows forever.
 *
 * ## `--dry-run`, because the first run is the frightening one
 *
 * Seven years of rows is a number nobody has an intuition for, and a purge that
 * cannot be previewed is one that gets postponed until it is deleting far more
 * than anybody expected.
 */
final class PurgeAuditLogCommand extends Command
{
    protected $signature = 'audit:purge {--dry-run : Report what would be deleted and delete nothing}';

    protected $description = 'Delete audit log entries older than the retention window (ADR-0025).';

    public function handle(): int
    {
        $days = (int) config('kaiki.audit.retention_days', 2555);

        if ($days < 1) {
            // A zero or negative window would delete the entire trail on the
            // next tick. Refused rather than obeyed: an env var typo must not
            // be able to erase seven years of evidence.
            $this->error("audit.retention_days is {$days}; refusing to purge. Set a positive number of days.");

            return self::FAILURE;
        }

        $before = Carbon::now()->subDays($days)->startOfDay();

        if ($this->option('dry-run')) {
            $this->info(sprintf(
                '%d audit entries are older than %s (%d days) and would be deleted.',
                AuditLog::olderThan($before),
                $before->toDateString(),
                $days,
            ));

            return self::SUCCESS;
        }

        $deleted = AuditLog::purge($before);

        $this->info(sprintf('Deleted %d audit entries older than %s.', $deleted, $before->toDateString()));

        return self::SUCCESS;
    }
}
