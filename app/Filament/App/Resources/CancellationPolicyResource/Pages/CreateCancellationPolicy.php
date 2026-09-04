<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\CancellationPolicyResource\Pages;

use App\Filament\App\Resources\CancellationPolicyResource;
use App\Models\CancellationPolicy;
use Filament\Resources\Pages\CreateRecord;

/**
 * Creation goes through the Action, not through Filament's own save.
 *
 * The one-default-per-tenant rule is not a form concern: the importer needs it
 * too, and a tenant's *first* policy has to become the default whether or not
 * anybody ticked the box — otherwise the first product created before anyone
 * thinks about cancellation terms has none at all.
 */
class CreateCancellationPolicy extends CreateRecord
{
    use ConsumesTierRepeater;

    protected static string $resource = CancellationPolicyResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): CancellationPolicy
    {
        return $this->savePolicy(new CancellationPolicy, $data);
    }
}
