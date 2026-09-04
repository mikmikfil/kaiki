<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\DepartureResource\Pages;

use App\Domain\Availability\Actions\UpdateDeparture;
use App\Filament\App\Resources\DepartureResource;
use App\Models\Departure;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditDeparture extends EditRecord
{
    protected static string $resource = DepartureResource::class;

    /**
     * Deliberately **no delete action**.
     *
     * §2.4: cancellation is a status, never a delete — bookings must keep
     * resolving `departure_id` to render a guest's history. The cancellation
     * workflow itself is M5 (CXL-6, OPS-6).
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        unset($data['confirm_conflict']);

        try {
            /** @var Departure $record */
            return app(UpdateDeparture::class)($record, $data);
        } catch (ValidationException $exception) {
            throw $this->attachToForm($exception);
        }
    }

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
