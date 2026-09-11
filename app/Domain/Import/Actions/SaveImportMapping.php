<?php

declare(strict_types=1);

namespace App\Domain\Import\Actions;

use App\Enums\BookingMode;
use App\Enums\ProductCategory;
use App\Models\ImportJob;
use App\Models\Product;
use App\Models\Vessel;

/**
 * Stores what the operator decided on the review screen, and re-runs the
 * verdicts so the screen shows the consequence at once (SAA-14).
 *
 * Every value is checked against what it may be — an action from three, a mode
 * and a category from their enums, a vessel and a linked product that belong to
 * this operator (the queries are tenant-scoped) — so a crafted form cannot
 * smuggle another operator's product id into the mapping.
 */
final class SaveImportMapping
{
    public function __construct(private readonly EvaluateImportRows $evaluate) {}

    /** @param array<string, mixed> $input */
    public function __invoke(ImportJob $job, array $input): ImportJob
    {
        $mapping = $job->mapping;

        foreach ((array) ($input['products'] ?? []) as $sourceId => $choice) {
            if (! is_array($choice) || ! isset($mapping['products'][(string) $sourceId])) {
                continue;
            }

            $action = in_array($choice['action'] ?? null, ['create', 'link', 'skip'], true) ? $choice['action'] : 'skip';

            $clean = ['action' => $action];

            if ($action === 'skip') {
                $clean['reason'] = 'operator';
            }

            if ($action === 'link') {
                $target = (int) ($choice['target_product_id'] ?? 0);
                $clean['target_product_id'] = $target > 0 && Product::query()->whereKey($target)->exists() ? $target : null;
            }

            if ($action === 'create') {
                $clean['mode'] = (BookingMode::tryFrom((string) ($choice['mode'] ?? '')) ?? BookingMode::PerSeat)->value;
                $clean['category'] = (ProductCategory::tryFrom((string) ($choice['category'] ?? '')) ?? ProductCategory::SharedFullDay)->value;
                $vessel = (int) ($choice['vessel_id'] ?? 0);
                $clean['vessel_id'] = $vessel > 0 && Vessel::query()->whereKey($vessel)->exists() ? $vessel : null;
            }

            $mapping['products'][(string) $sourceId] = $clean;
        }

        foreach ((array) ($input['people_types'] ?? []) as $sourceId => $band) {
            if (! is_array($band) || ! isset($mapping['people_types'][(string) $sourceId])) {
                continue;
            }

            $code = preg_replace('/[^a-z0-9_]/', '', mb_strtolower((string) ($band['age_band_code'] ?? ''))) ?: 'type_' . $sourceId;

            $mapping['people_types'][(string) $sourceId] = array_merge($mapping['people_types'][(string) $sourceId], [
                'age_band_code' => mb_substr($code, 0, 32),
                'min_age' => self::age($band['min_age'] ?? null),
                'max_age' => self::age($band['max_age'] ?? null),
                'counts_toward_capacity' => (bool) ($band['counts_toward_capacity'] ?? true),
            ]);
        }

        if (is_string($input['bookings']['import_from'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}/', $input['bookings']['import_from']) === 1) {
            $mapping['bookings']['import_from'] = substr($input['bookings']['import_from'], 0, 10);
        }

        $job->forceFill(['mapping' => $mapping])->save();

        ($this->evaluate)($job);

        return $job->refresh();
    }

    private static function age(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, min(120, (int) $value)) : null;
    }
}
