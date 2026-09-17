<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\DiscountCodeResource\Pages;

use App\Filament\App\Resources\DiscountCodeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDiscountCode extends CreateRecord
{
    protected static string $resource = DiscountCodeResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return DiscountCodeResource::valueIntoColumn($data);
    }

    protected function getRedirectUrl(): string
    {
        return DiscountCodeResource::getUrl('index');
    }
}
