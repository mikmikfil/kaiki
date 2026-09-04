<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\VesselResource\Pages;

use App\Filament\App\Resources\VesselResource;
use App\Filament\App\Resources\VesselResource\Pages\Concerns\TranslatesVesselFormData;
use Filament\Resources\Pages\CreateRecord;

class CreateVessel extends CreateRecord
{
    use TranslatesVesselFormData;

    protected static string $resource = VesselResource::class;
}
