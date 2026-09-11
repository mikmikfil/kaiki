<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Domain\Import\Data\SourceRecord;
use App\Models\ImportJob;

/**
 * Where an import's records come from (spec EXT-5).
 *
 * > `App\Contracts\ImportSource` — MVP implementation `WooCommerceYithSource`.
 * > A future Bokun or CSV importer reuses the dry-run and mapping-review
 * > machinery.
 *
 * A source only **reads**. It turns whatever the other system produced into
 * {@see SourceRecord}s; deciding what they become, reviewing that with the
 * operator and writing it are the machinery's, so a second source is one class.
 */
interface ImportSource
{
    /**
     * Every record the job's source holds, categories and person types first.
     *
     * @return iterable<SourceRecord>
     */
    public function records(ImportJob $job): iterable;
}
