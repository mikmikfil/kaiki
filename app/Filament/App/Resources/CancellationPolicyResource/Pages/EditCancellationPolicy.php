<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\CancellationPolicyResource\Pages;

use App\Filament\App\Resources\CancellationPolicyResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Editing is safe by design: CXL-1 froze the policy onto every existing booking
 * at payment time, so nothing changed here can rewrite money already owed.
 */
class EditCancellationPolicy extends EditRecord
{
    use ConsumesTierRepeater;

    protected static string $resource = CancellationPolicyResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make(), RestoreAction::make()];
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->savePolicy($record, $data);
    }
}
