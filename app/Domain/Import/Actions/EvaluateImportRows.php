<?php

declare(strict_types=1);

namespace App\Domain\Import\Actions;

use App\Domain\Import\Support\SourceValues;
use App\Enums\ImportRowStatus;
use App\Enums\ImportRowType;
use App\Models\ImportJob;
use App\Models\ImportJobRow;
use App\Models\Vessel;
use Illuminate\Support\Collection;

/**
 * Applies the mapping to every row and says what will happen to each (SAA-14).
 *
 * This is the dry run's verdict: `mapped` (will be imported) or `skipped`, and
 * for every skip a reason the review screen prints beside the record. It
 * writes to `import_job_rows` and `import_jobs.stats` **only** — nothing in the
 * catalogue or the bookings — and it runs again every time the operator changes
 * the mapping, so the screen always shows the consequence of their choices.
 *
 * Rows already `imported` are left alone: after a stopped run the review screen
 * is also where it is resumed, and an imported row has nothing left to decide.
 */
final class EvaluateImportRows
{
    public function __invoke(ImportJob $job): void
    {
        $mapping = $job->mapping;

        /** @var Collection<int, ImportJobRow> $rows */
        $rows = $job->rows()->get();

        $productVerdicts = [];

        foreach ($rows as $row) {
            if ($row->status === ImportRowStatus::Imported) {
                if ($row->source_type === ImportRowType::Product) {
                    $productVerdicts[$row->source_id] = true;
                }

                continue;
            }

            [$status, $messages] = match ($row->source_type) {
                ImportRowType::Category => [ImportRowStatus::Mapped, [[
                    'key' => 'imports.notes.category',
                    'params' => ['category' => (string) ($mapping['categories'][$row->source_id] ?? '')],
                ]]],
                ImportRowType::PeopleType => [ImportRowStatus::Mapped, [[
                    'key' => 'imports.notes.people_type',
                    'params' => ['code' => (string) ($mapping['people_types'][$row->source_id]['age_band_code'] ?? '')],
                ]]],
                ImportRowType::Product => $this->product($row, $mapping),
                ImportRowType::Customer => [ImportRowStatus::Skipped, []],
                ImportRowType::Booking => [ImportRowStatus::Pending, []],
            };

            if ($row->source_type === ImportRowType::Product) {
                $productVerdicts[$row->source_id] = $status === ImportRowStatus::Mapped;
            }

            $row->forceFill(['status' => $status, 'messages' => $messages])->save();
        }

        foreach ($rows->where('source_type', ImportRowType::Booking) as $row) {
            if ($row->status === ImportRowStatus::Imported) {
                continue;
            }

            [$status, $messages] = $this->booking($row, $mapping, $productVerdicts);

            $row->forceFill(['status' => $status, 'messages' => $messages])->save();
        }

        $job->forceFill(['stats' => self::stats($job)])->save();
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @return array{0: ImportRowStatus, 1: list<array<string, mixed>>}
     */
    private function product(ImportJobRow $row, array $mapping): array
    {
        $choice = (array) ($mapping['products'][$row->source_id] ?? []);
        $action = (string) ($choice['action'] ?? 'skip');
        $payload = $row->source_payload;

        if ($action === 'skip') {
            return [ImportRowStatus::Skipped, [['key' => 'imports.reasons.' . ((string) ($choice['reason'] ?? 'operator'))]]];
        }

        if ($action === 'link') {
            return empty($choice['target_product_id'])
                ? [ImportRowStatus::Skipped, [['key' => 'imports.reasons.no_link_target']]]
                : [ImportRowStatus::Mapped, [['key' => 'imports.notes.will_link']]];
        }

        $vesselId = $choice['vessel_id'] ?? ($mapping['options']['vessel_id'] ?? null);

        if (empty($vesselId) || ! Vessel::query()->whereKey((int) $vesselId)->exists()) {
            return [ImportRowStatus::Skipped, [['key' => 'imports.reasons.no_vessel']]];
        }

        $messages = [['key' => 'imports.notes.will_create']];

        // Kaiki keeps every title in Greek and English; a WordPress shop has
        // one. The same text goes into both, and the operator is told.
        $messages[] = ['key' => 'imports.warnings.single_language'];

        if (SourceValues::cents($payload['price'] ?? $payload['base_cost'] ?? null) === null) {
            $messages[] = ['key' => 'imports.warnings.no_price'];
        }

        return [ImportRowStatus::Mapped, $messages];
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @param  array<string, bool>  $productVerdicts
     * @return array{0: ImportRowStatus, 1: list<array<string, mixed>>}
     */
    private function booking(ImportJobRow $row, array $mapping, array $productVerdicts): array
    {
        $payload = $row->source_payload;
        $status = mb_strtolower((string) ($payload['status'] ?? ''));
        $statuses = array_map('mb_strtolower', (array) ($mapping['bookings']['statuses'] ?? []));

        if (! in_array($status, $statuses, true)) {
            return [ImportRowStatus::Skipped, [['key' => 'imports.reasons.status', 'params' => ['status' => $status === '' ? '—' : $status]]]];
        }

        $start = SourceValues::localDateTime(is_string($payload['from'] ?? null) ? $payload['from'] : null);

        if ($start === null) {
            return [ImportRowStatus::Skipped, [['key' => 'imports.reasons.bad_date', 'params' => ['value' => (string) ($payload['from'] ?? '')]]]];
        }

        $from = (string) ($mapping['bookings']['import_from'] ?? '');

        if ($from !== '' && $start['date'] < $from) {
            return [ImportRowStatus::Skipped, [['key' => 'imports.reasons.past', 'params' => ['date' => $start['date'], 'from' => $from]]]];
        }

        $productId = (string) ($payload['product_id'] ?? '');

        if (! array_key_exists($productId, $productVerdicts)) {
            return [ImportRowStatus::Skipped, [['key' => 'imports.reasons.unknown_product', 'params' => ['product' => (string) ($payload['product'] ?? $productId)]]]];
        }

        if ($productVerdicts[$productId] !== true) {
            return [ImportRowStatus::Skipped, [['key' => 'imports.reasons.product_skipped', 'params' => ['product' => (string) ($payload['product'] ?? $productId)]]]];
        }

        $persons = (int) ($payload['persons'] ?? 0);

        if ($persons < 1 && SourceValues::personCounts(is_string($payload['person_types'] ?? null) ? $payload['person_types'] : null) === []) {
            return [ImportRowStatus::Skipped, [['key' => 'imports.reasons.no_pax']]];
        }

        $messages = [['key' => 'imports.notes.booking', 'params' => ['date' => $start['date'], 'time' => $start['time']]]];

        if (($payload['email'] ?? null) === null) {
            $messages[] = ['key' => 'imports.warnings.no_email'];
        }

        if (SourceValues::cents(is_string($payload['total'] ?? null) ? $payload['total'] : null) === null) {
            $messages[] = ['key' => 'imports.warnings.no_total'];
        }

        return [ImportRowStatus::Mapped, $messages];
    }

    /**
     * Counts per type per status, the shape of §3.15's `import_jobs.stats`.
     *
     * @return array<string, array<string, int>>
     */
    public static function stats(ImportJob $job): array
    {
        $stats = [];

        foreach ($job->rows()->get(['source_type', 'status']) as $row) {
            $type = $row->source_type->value;
            $status = $row->status->value;
            $stats[$type][$status] = ($stats[$type][$status] ?? 0) + 1;
        }

        return $stats;
    }
}
