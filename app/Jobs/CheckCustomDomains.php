<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Tenancy\Actions\CheckDomains;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The scheduled half of custom-domain verification (#109, HOS-3).
 *
 * Queued rather than run inline in the scheduler, like every other sweep here:
 * a DNS lookup per domain is a network round trip, and a scheduler tick that
 * blocks on the internet is a scheduler tick that eventually overlaps itself.
 */
class CheckCustomDomains implements ShouldQueue
{
    use Queueable;

    public function handle(CheckDomains $check): void
    {
        $check();
    }
}
