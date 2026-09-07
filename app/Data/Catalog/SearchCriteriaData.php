<?php

declare(strict_types=1);

namespace App\Data\Catalog;

use App\Domain\Catalog\Support\SearchFilters;
use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;

/**
 * What a guest asked the catalogue, after the operator's settings were applied.
 *
 * ## Everything here is already permitted
 *
 * A filter the operator switched off is **null by the time it reaches this
 * object**, not null-checked later. {@see SearchFilters} decides that on the way
 * in, so the Action cannot accidentally honour a value the operator disabled —
 * the failure the issue's own note calls out as the one that passes review.
 *
 * `applied` is what came through, for the response's `meta` block: a guest whose
 * `?vessel=` was dropped can see that it was, rather than wondering why the
 * results ignored it.
 */
final class SearchCriteriaData extends Data
{
    /**
     * @param  list<string>  $filtersEnabled
     * @param  array<string, mixed>  $applied
     */
    public function __construct(
        public readonly Carbon $date,
        public readonly int $pax,
        public readonly ?string $portUuid = null,
        public readonly ?string $category = null,
        public readonly ?int $durationMaxMinutes = null,
        public readonly ?int $priceMaxCents = null,
        public readonly ?string $vesselUuid = null,
        public readonly array $filtersEnabled = [],
        public readonly array $applied = [],
    ) {}
}
