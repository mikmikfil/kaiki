<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PolicyTemplateResource\Pages;

use App\Filament\Admin\Resources\PolicyTemplateResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * Delete is offered here because nothing points at a template — an operator who
 * chose this ladder holds a copy of it, not a reference to it. Retiring is
 * still the better move and the form leads with it.
 */
class EditPolicyTemplate extends EditRecord
{
    protected static string $resource = PolicyTemplateResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
