<?php

declare(strict_types=1);

namespace Tests\Support\Catalog;

use App\Domain\Catalog\Actions\GuardVesselCapacity;
use App\Domain\Catalog\Contracts\VesselCapacityClaims;
use App\Domain\Catalog\Data\CapacityClaim;
use App\Models\Vessel;

/**
 * A claim source standing in for `products` (#18) and `departures` (#23).
 *
 * The guard is real; the tables it will eventually ask are not built yet. This
 * is what makes issue #16's fourth acceptance criterion assertable **today**
 * rather than in two issues' time — the refusal, its message, its localisation
 * and both enforcement points are all exercised through the same code path
 * production will use, with only the data source substituted.
 *
 * It is a test double rather than a `Departure` factory on purpose: a fake that
 * knew about departures would have to be rewritten when the real table lands,
 * and the point of the interface is that it does not.
 */
final class FakeCapacityClaims implements VesselCapacityClaims
{
    /**
     * @param  list<array{kind: string, label: string, pax: int}>  $records
     */
    public function __construct(private readonly array $records = []) {}

    /**
     * Register this source for the duration of the test.
     *
     * Rebinds the Action rather than the tag, because `tag()` is read once when
     * the singleton is built and adding to it afterwards does nothing — a
     * subtlety that would otherwise show up as a test that passes for the wrong
     * reason (no claims found, therefore no refusal).
     *
     * @param  list<array{kind: string, label: string, pax: int}>  $records
     */
    public static function register(array $records): void
    {
        app()->singleton(
            GuardVesselCapacity::class,
            static fn (): GuardVesselCapacity => new GuardVesselCapacity([new self($records)]),
        );
    }

    /** @return list<CapacityClaim> */
    public function exceeding(Vessel $vessel, int $newCapacity): array
    {
        $claims = [];

        foreach ($this->records as $record) {
            if ($record['pax'] > $newCapacity) {
                $claims[] = new CapacityClaim(
                    kind: $record['kind'],
                    label: $record['label'],
                    pax: $record['pax'],
                );
            }
        }

        return $claims;
    }
}
