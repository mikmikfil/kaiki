<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\RatePlanResource\Pages;

use App\Filament\App\Resources\RatePlanResource;
use App\Models\RatePlan;
use Filament\Resources\Pages\CreateRecord;

class CreateRatePlan extends CreateRecord
{
    use ConsumesBandPrices;

    protected static string $resource = RatePlanResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): RatePlan
    {
        return $this->saveRatePlan(new RatePlan, $data);
    }
}
