<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\FaqResource\Pages;

use App\Filament\App\Resources\FaqResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFaq extends CreateRecord
{
    protected static string $resource = FaqResource::class;
}
