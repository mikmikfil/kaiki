<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VatRateResource\Pages;

use App\Filament\Admin\Resources\VatRateResource;
use App\Models\VatRate;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

/**
 * No delete action, deliberately.
 *
 * `docs/data-model.md` §2.3 gives this table no soft deletes: a rate is
 * superseded by a new row and retired with `is_selectable`, never removed. The
 * foreign keys from `products` and `extras` are `restrictOnDelete`, so a delete
 * button would only ever surface a constraint violation to a super-admin.
 *
 * ## But there is a way forward from here
 *
 * `rate_bp` and `valid_from` are disabled on this screen, and that is the whole
 * point of it — see {@see VatRateResource} for why rewriting a percentage in
 * place is not allowed. What was missing is what to do *instead*: a super-admin
 * who came here because the law changed found two locked fields, a paragraph
 * explaining why, and no button (Mike, 2026-09-23).
 *
 * «Νέος συντελεστής» is that button. It opens the create form carrying this
 * row's identity, so the new one is recognisably the same rate at a later date,
 * and leaves the percentage and the date blank — those being the two things
 * actually changing.
 */
class EditVatRate extends EditRecord
{
    protected static string $resource = VatRateResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('supersede')
                ->label(__('vat.actions.supersede.label'))
                ->icon('heroicon-m-plus-circle')
                ->url(fn (VatRate $record): string => VatRateResource::getUrl('create', [
                    'supersede' => $record->getKey(),
                ])),
        ];
    }
}
