<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\SeasonResource\Pages;

use App\Domain\Catalog\Actions\SaveSeason;
use App\Models\Season;
use Illuminate\Database\Eloquent\Model;

/**
 * Hands the form's state to the domain Action, ranges and all.
 *
 * The repeater is declared with `relationship()` so it renders and hydrates
 * from `dateRanges`, but the **write** is taken back. PRC-4's tie check has to
 * run against the state the save would actually produce, inside the same
 * transaction — letting Filament write the ranges separately would put the
 * check and the rows it checks in different transactions, and a concurrent
 * save could then slip a tie past both.
 *
 * Shared by create and edit because both have exactly the same job, and a
 * copy-pasted second version is the one that stops calling the Action.
 */
trait ConsumesRangeRepeater
{
    /** @param array<string, mixed> $data */
    protected function saveSeason(Model $record, array $data): Season
    {
        /** @var list<array{starts_on: string, ends_on: string}> $ranges */
        $ranges = array_values(array_map(
            static fn (array $range): array => [
                'starts_on' => (string) $range['starts_on'],
                'ends_on' => (string) $range['ends_on'],
            ],
            $data['dateRanges'] ?? [],
        ));

        unset($data['dateRanges']);

        /** @var Season $record */
        return app(SaveSeason::class)($record, $data, $ranges);
    }
}
