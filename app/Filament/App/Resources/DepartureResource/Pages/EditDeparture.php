<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\DepartureResource\Pages;

use App\Domain\Availability\Actions\AssignDepartureCrew;
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

        // Captain and crew are theirs to write (2026-09-24), not the
        // timetable's: `UpdateDeparture` guards times and seats.
        $captain = $data['captain_user_id'] ?? null;
        $captainName = $data['captain_name'] ?? null;
        $crew = (array) ($data['crew_user_ids'] ?? []);
        $crewNames = array_key_exists('crew_names', $data) ? (array) ($data['crew_names'] ?? []) : null;
        unset($data['captain_user_id'], $data['captain_name'], $data['crew_user_ids'], $data['crew_names']);

        if (! $record instanceof Departure) {
            return $record;
        }

        try {
            $record = app(UpdateDeparture::class)($record, $data);

            return app(AssignDepartureCrew::class)($record, $captain, $captainName, $crew, $crewNames);
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
