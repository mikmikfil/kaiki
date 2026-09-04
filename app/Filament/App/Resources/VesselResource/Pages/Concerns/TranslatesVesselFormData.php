<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\VesselResource\Pages\Concerns;

use App\Filament\App\Resources\VesselResource;
use App\Models\Vessel;
use Illuminate\Support\Arr;

/**
 * The two places the vessel form and the vessel columns are not the same shape.
 *
 * Shared by the create and edit pages so they cannot drift — the failure mode
 * of writing this twice is that creating a boat rounds its length one way and
 * editing it rounds the other, which nobody notices until a spec sheet
 * disagrees with itself.
 *
 * **1. Length.** The form asks for metres because that is how an operator
 * describes a boat; the column is `length_cm`, an integer, because
 * `docs/data-model.md` §2.3 refuses decimals for anything but coordinates. 13.5
 * metres is 1350, exactly, forever.
 *
 * **2. Specs.** §3.9 requires that *"unknown keys are preserved but not
 * rendered"*. The form only knows {@see VesselResource::SPEC_KEYS}, so writing
 * its state straight to the column would silently delete whatever an import or
 * a later version put there. Merging keeps them.
 */
trait TranslatesVesselFormData
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['length_m'] = isset($data['length_cm']) && is_numeric($data['length_cm'])
            ? (int) $data['length_cm'] / 100
            : null;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->normaliseVesselData($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->normaliseVesselData($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normaliseVesselData(array $data): array
    {
        $data['length_cm'] = isset($data['length_m']) && is_numeric($data['length_m'])
            // Rounded, not cast: `(int) (13.5 * 100)` is 1349 on a binary float,
            // and a boat that loses a centimetre on every save eventually loses
            // a metre.
            ? (int) round((float) $data['length_m'] * 100)
            : null;

        unset($data['length_m']);

        $submitted = is_array($data['specs'] ?? null) ? $data['specs'] : [];

        // An empty box means "not stated", not `""`. Storing the empty string
        // would make §3.9's spec sheet render a blank row for every field the
        // operator left alone.
        $submitted = array_filter(
            Arr::only($submitted, VesselResource::SPEC_KEYS),
            static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== [],
        );

        // `getRecord()` is `?Model` on the create page and `Model` on the edit
        // page, so the check is `instanceof` rather than a null check or an
        // annotation: on create there is nothing to preserve, and on edit this
        // is what tells the reader the shape being merged into is a vessel's.
        $record = $this->getRecord();

        $preserved = $record instanceof Vessel
            ? Arr::except($record->specs, VesselResource::SPEC_KEYS)
            : [];

        $data['specs'] = [...$preserved, ...$submitted];

        return $data;
    }
}
