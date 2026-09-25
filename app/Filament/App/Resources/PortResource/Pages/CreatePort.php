<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\PortResource\Pages;

use App\Filament\App\Resources\PortResource;
use App\Filament\App\Support\ReturnsToFirstSteps;
use Filament\Resources\Pages\CreateRecord;

class CreatePort extends CreateRecord
{
    use ReturnsToFirstSteps;

    protected static string $resource = PortResource::class;

    /** Back to the checklist when it sent the operator here (Mike, 25/9). */
    protected function getRedirectUrl(): string
    {
        return $this->firstStepsRedirectUrl(parent::getRedirectUrl());
    }
}
