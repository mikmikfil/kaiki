<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VatRateResource\Pages;

use App\Filament\Admin\Resources\VatRateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateVatRate extends CreateRecord
{
    protected static string $resource = VatRateResource::class;
}
