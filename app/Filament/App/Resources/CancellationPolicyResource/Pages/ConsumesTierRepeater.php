<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\CancellationPolicyResource\Pages;

use App\Domain\Catalog\Actions\SaveCancellationPolicy;
use App\Models\CancellationPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Hands the form's state to the domain Action, tiers and all.
 *
 * Shared by create and edit because both have exactly the same job and a
 * copy-pasted second version is the one that stops calling the Action.
 *
 * The repeater is declared with `relationship()` so it renders and hydrates
 * from `tiers`, but the **write** is taken back from Filament here: the Action
 * replaces the ladder in a transaction alongside the default-policy demotion,
 * and letting Filament write the rows separately would put those two in
 * different transactions for no reason.
 */
trait ConsumesTierRepeater
{
    /** @param array<string, mixed> $data */
    protected function savePolicy(Model $record, array $data): CancellationPolicy
    {
        /** @var list<array{days_before: int|string, refund_percent: int|string}> $tiers */
        $tiers = array_values($data['tiers'] ?? []);

        unset($data['tiers']);

        /** @var CancellationPolicy $record */
        return app(SaveCancellationPolicy::class)($record, $data, $tiers);
    }
}
