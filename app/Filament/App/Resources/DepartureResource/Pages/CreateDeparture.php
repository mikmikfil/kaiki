<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\DepartureResource\Pages;

use App\Domain\Availability\Actions\CreateManualDeparture;
use App\Filament\App\Resources\DepartureResource;
use App\Models\Departure;
use App\Models\Product;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateDeparture extends CreateRecord
{
    protected static string $resource = DepartureResource::class;

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    protected function handleRecordCreation(array $data): Departure
    {
        $confirmed = (bool) ($data['confirm_conflict'] ?? false);
        unset($data['confirm_conflict']);

        $product = Product::query()->findOrFail((int) $data['product_id']);

        try {
            return app(CreateManualDeparture::class)($product, $data, $confirmed);
        } catch (ValidationException $exception) {
            throw $this->attachToForm($exception);
        }
    }

    /**
     * Re-key the Action's errors onto the form's fields.
     *
     * The Action reports on `local_time` — which is what the API and the
     * importer will see — and Livewire looks for `data.local_time`. Without the
     * prefix the conflict warning renders as an unattached banner, and the
     * operator has no idea which control to change to make it go away.
     */
    private function attachToForm(ValidationException $exception): ValidationException
    {
        $prefix = $this->getFormStatePath();

        if ($prefix === null || $prefix === '') {
            return $exception;
        }

        $messages = [];

        foreach ($exception->errors() as $key => $bag) {
            $messages["{$prefix}.{$key}"] = $bag;
        }

        return ValidationException::withMessages($messages);
    }
}
