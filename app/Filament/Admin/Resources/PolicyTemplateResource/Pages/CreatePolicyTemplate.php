<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PolicyTemplateResource\Pages;

use App\Filament\Admin\Resources\PolicyTemplateResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePolicyTemplate extends CreateRecord
{
    protected static string $resource = PolicyTemplateResource::class;
}
