<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Payments\Actions\ReconcilePendingPayments;
use Illuminate\Console\Command;

/**
 * Ask the gateway about payments no webhook confirmed (`docs/api.md` item 12).
 *
 * Scheduled every five minutes, and useful by hand on the day a webhook turns
 * out to have been registered against the wrong event — which is what prompted
 * it. Safe to run at any time: it asks, and it acts only on a settled answer.
 */
class ReconcilePaymentsCommand extends Command
{
    protected $signature = 'payments:reconcile';

    protected $description = 'Ask the gateway what happened to payments still waiting on a webhook';

    public function handle(ReconcilePendingPayments $reconcile): int
    {
        $tally = $reconcile();

        $this->components->info(sprintf(
            'Checked %d, confirmed %d, failed %d, no answer %d.',
            $tally['checked'],
            $tally['confirmed'],
            $tally['failed'],
            $tally['unanswered'],
        ));

        return self::SUCCESS;
    }
}
