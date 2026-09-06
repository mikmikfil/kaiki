<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Booking\Actions\CompleteDepartures;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * BKG-21's completion sweep, on the schedule.
 *
 * A job rather than a closure in `routes/console.php`, like every other sweep
 * in the project: the schedule entry stays one readable line, and the same work
 * can be run by hand against a single tenant when somebody asks why a trip from
 * Tuesday is still showing as confirmed.
 */
class CompleteDeparturesJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly ?int $limit = null) {}

    public function handle(CompleteDepartures $complete): void
    {
        $complete($this->limit);
    }
}
