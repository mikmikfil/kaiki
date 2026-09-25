<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\SeasonResource\Pages;

use App\Filament\App\Resources\SeasonResource;
use App\Filament\App\Support\ReturnsToFirstSteps;
use App\Models\Season;
use Filament\Resources\Pages\CreateRecord;

class CreateSeason extends CreateRecord
{
    use ConsumesRangeRepeater;
    use ReturnsToFirstSteps;

    protected static string $resource = SeasonResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Season
    {
        return $this->saveSeason(new Season, $data);
    }

    /** Back to the checklist when it sent the operator here (Mike, 25/9). */
    protected function getRedirectUrl(): string
    {
        return $this->firstStepsRedirectUrl(parent::getRedirectUrl());
    }
}
