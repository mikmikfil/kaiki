<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VatRateResource\Pages;

use App\Filament\Admin\Resources\VatRateResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

/**
 * No delete action, deliberately.
 *
 * `docs/data-model.md` §2.3 gives this table no soft deletes: a rate is
 * superseded by a new row and retired with `is_selectable`, never removed. The
 * foreign keys from `products` and `extras` are `restrictOnDelete`, so a delete
 * button would only ever surface a constraint violation to a super-admin.
 */
class EditVatRate extends EditRecord
{
    protected static string $resource = VatRateResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
