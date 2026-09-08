<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Enums\ExportDateBasis;
use App\Enums\ExportStatus;
use App\Enums\ExportType;
use App\Jobs\ExecuteGatewayRefund;
use App\Jobs\RunExportJob;
use App\Models\ExportJob;
use Illuminate\Support\Carbon;

/**
 * An operator asks for a CSV (spec OPS-17, OPS-18).
 *
 * ## The row exists before the job does
 *
 * The same ordering {@see ExecuteGatewayRefund} uses, and for the
 * same reason: a queued job that creates its own record has nothing to report
 * to when it dies before its first line runs. Writing the row first means every
 * export an operator asks for is visible on the screen immediately — *queued*
 * is an answer, and a button that appears to do nothing is not.
 *
 * ## Re-running is a new row, never a refresh
 *
 * Overwriting the previous file is the tempting economy. It changes what a
 * colleague downloads from a link they were already given, silently and after
 * the fact, and it makes "the numbers moved between Tuesday and Thursday" a
 * question nobody can answer. An export is a statement about a window at a
 * moment; a second one is a second statement.
 */
final class RequestExport
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __invoke(
        ExportType $type,
        ?ExportDateBasis $basis = null,
        ?Carbon $from = null,
        ?Carbon $to = null,
        array $filters = [],
        ?int $userId = null,
    ): ExportJob {
        $basis = $this->resolveBasis($type, $basis);

        [$from, $to] = $this->orderWindow($from, $to);

        $export = ExportJob::create([
            'user_id' => $userId,
            'type' => $type,
            'date_basis' => $basis,
            'from_date' => $from?->toDateString(),
            'to_date' => $to?->toDateString(),
            'filters' => $this->cleanFilters($filters),
            'status' => ExportStatus::Queued,
        ]);

        RunExportJob::dispatch($export->getKey());

        return $export;
    }

    /**
     * A basis this export type can actually answer.
     *
     * A guests export windowed on `paid` would run and produce rows, and the
     * rows would answer a question nobody asked. Falling back to the type's own
     * default is right here — unlike the manifest's column fallback, nothing
     * sensitive is disclosed by choosing wrongly, and the choice is printed on
     * the row and in the filename where the operator will see it.
     */
    private function resolveBasis(ExportType $type, ?ExportDateBasis $basis): ExportDateBasis
    {
        if ($basis !== null && $type->allowsDateBasis($basis)) {
            return $basis;
        }

        return $type->defaultDateBasis();
    }

    /**
     * A window whose ends are the right way round.
     *
     * Swapped dates are a typo, and refusing one costs the operator a round
     * trip to be told something the system could see for itself. An inverted
     * window would otherwise produce a legitimate-looking empty file.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function orderWindow(?Carbon $from, ?Carbon $to): array
    {
        if ($from !== null && $to !== null && $from->isAfter($to)) {
            return [$to, $from];
        }

        return [$from, $to];
    }

    /**
     * Only the filters this system knows about, and only when they say something.
     *
     * Storing whatever arrived would make the `filters` column a place where a
     * form's incidental state accumulates, and the column exists so that a run
     * can be reproduced — which requires it to hold what the query used and
     * nothing else.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function cleanFilters(array $filters): array
    {
        $clean = [];

        $statuses = $filters['statuses'] ?? null;

        if (is_array($statuses) && $statuses !== []) {
            $clean['statuses'] = array_values(array_map('strval', $statuses));
        }

        foreach (['product_uuid', 'vessel_uuid'] as $key) {
            $value = $filters[$key] ?? null;

            if (is_string($value) && $value !== '') {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
