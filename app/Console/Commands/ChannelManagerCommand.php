<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Channels\Support\ChannelManagerFlag;
use Illuminate\Console\Command;

/**
 * Open, close or report EXT-2's `channel_manager` switch (ADR-0034).
 *
 * ## Why this is a console command and not a screen
 *
 * Every other switch in this product has a screen, because every other switch
 * is an operator's or an administrator's ordinary decision. This one asserts
 * something different: **that GetYourGuide has certified this integration**.
 *
 * That is not a business decision somebody makes between two bookings. It is a
 * statement about a third party's sign-off, made once, by whoever has the
 * certification email in front of them. A button on Edit Merchant beside the
 * per-merchant toggles would put it one careless click away from the thing it
 * exists to prevent — and ADR-0034 ships the code months before the
 * certification, which is exactly the window where that click would sell seats
 * through a contract nobody signed.
 *
 * ## Opening it does not turn anything on
 *
 * It only stops the platform from refusing outright. Each operator still needs
 * `tenants.getyourguide_enabled` set on Edit Merchant, with a reason, in their
 * own audit trail — and their own credentials saved and verified. This is the
 * outer of two locks, never the inner one.
 */
class ChannelManagerCommand extends Command
{
    protected $signature = 'channels:manager
                            {action=status : status, open or close}';

    protected $description = 'Report or change whether the OTA channels are allowed at all (EXT-2, ADR-0034)';

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'status' => $this->report(),
            'open' => $this->open(),
            'close' => $this->close(),
            default => $this->unknown($action),
        };
    }

    private function report(): int
    {
        if (ChannelManagerFlag::isOpen()) {
            $this->info('channel_manager is OPEN — OTA channels may run for merchants that have them switched on.');

            return self::SUCCESS;
        }

        $this->warn('channel_manager is CLOSED — no OTA channel will send or answer anything, whatever /admin says.');

        return self::SUCCESS;
    }

    private function open(): int
    {
        if (ChannelManagerFlag::isOpen()) {
            $this->info('Already open. Nothing changed.');

            return self::SUCCESS;
        }

        // Asked in words rather than with a --force flag, because the thing
        // being confirmed is not "are you sure" but "has the certification
        // actually happened" — and a flag can be pasted from a runbook by
        // somebody who does not know the answer.
        $this->warn('This allows OTA channels to run. GetYourGuide requires a signed contract and three-phase certification first (ADR-0034).');

        if (! $this->confirm('Has that certification passed?', false)) {
            $this->line('Left closed.');

            return self::SUCCESS;
        }

        ChannelManagerFlag::open();
        $this->info('channel_manager is now OPEN.');
        $this->line('Each merchant still needs its own switch on Edit Merchant, with a reason, plus their own credentials.');
        $this->line('The operator manual still says Kaiki will not do this — docs/manual/chapter-whats-coming.html needs rewriting now.');

        return self::SUCCESS;
    }

    private function close(): int
    {
        ChannelManagerFlag::close();
        $this->info('channel_manager is now CLOSED. No OTA channel will send or answer anything.');

        return self::SUCCESS;
    }

    private function unknown(string $action): int
    {
        $this->error("Unknown action [{$action}]. Use status, open or close.");

        return self::FAILURE;
    }
}
