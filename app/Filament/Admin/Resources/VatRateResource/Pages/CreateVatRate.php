<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VatRateResource\Pages;

use App\Filament\Admin\Resources\VatRateResource;
use App\Models\VatRate;
use Filament\Resources\Pages\CreateRecord;

/**
 * Also the way a rate is *changed* (Mike, 2026-09-23: *«vat rate on admin,
 * cannot change %»*).
 *
 * He is right that the percentage cannot be edited, and it must not be — §2.3,
 * and {@see VatRateResource} gives the reason: rewriting `rate_bp` in place
 * changes what every product pointing at the row resolves to, silently and
 * backwards. The screen said so in its help text and then offered nothing to do
 * about it, which is how a rule reads as a fault.
 *
 * So «Νέος συντελεστής» on the edit screen arrives here carrying the row it
 * supersedes. Everything that identifies the rate is filled in — the code
 * especially, because the code is what makes the new row the *same* rate at a
 * later date rather than a different one.
 *
 * **The rate and the date stay empty on purpose.** They are the two answers
 * this form exists to collect, and a pre-filled percentage is one a super-admin
 * can save without reading.
 */
class CreateVatRate extends CreateRecord
{
    protected static string $resource = VatRateResource::class;

    protected function fillForm(): void
    {
        $previous = $this->supersededRate();

        if (! $previous instanceof VatRate) {
            parent::fillForm();

            return;
        }

        $this->form->fill([
            'code' => $previous->code,
            'vat_category' => $previous->vat_category,
            'description' => $previous->getTranslations('description'),
            'is_selectable' => true,
        ]);
    }

    /** The rate this one replaces, when the edit screen sent us here. */
    private function supersededRate(): ?VatRate
    {
        $id = request()->integer('supersede');

        return $id > 0 ? VatRate::query()->find($id) : null;
    }
}
