<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ProductResource\Pages;

use App\Filament\App\Resources\ProductResource;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    use ConsumesAgeBands;

    protected static string $resource = ProductResource::class;

    /**
     * A new trip is always saved as a draft first (2026-09-17): it cannot be
     * published before it has a price list, and those are added on the edit
     * page this one leads to. «Δημοσίευση» waits there.
     *
     * No «create another»: it made a second click on a slow connection look
     * like the first one had not worked.
     */
    protected static bool $canCreateAnother = false;

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label(__('catalog.product.status_actions.save_draft'));
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Product
    {
        return $this->saveProductWithBands(new Product, $data);
    }
}
