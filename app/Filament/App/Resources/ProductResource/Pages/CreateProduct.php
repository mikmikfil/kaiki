<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ProductResource\Pages;

use App\Filament\App\Resources\ProductResource;
use App\Models\Product;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    use ConsumesAgeBands;

    protected static string $resource = ProductResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Product
    {
        return $this->saveProductWithBands(new Product, $data);
    }
}
