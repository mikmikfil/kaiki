<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\PortResource\Pages;

use App\Filament\App\Resources\PortResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePort extends CreateRecord
{
    protected static string $resource = PortResource::class;
}
