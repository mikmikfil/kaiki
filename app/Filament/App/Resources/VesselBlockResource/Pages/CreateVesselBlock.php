<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\VesselBlockResource\Pages;

use App\Domain\Availability\Actions\CreateVesselBlock as CreateVesselBlockAction;
use App\Domain\Availability\Actions\RecomputeDepartureBlockedFlags;
use App\Filament\App\Resources\VesselBlockResource;
use App\Models\Vessel;
use App\Models\VesselBlock;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CreateVesselBlock extends CreateRecord
{
    protected static string $resource = VesselBlockResource::class;

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    protected function handleRecordCreation(array $data): VesselBlock
    {
        $vessel = Vessel::query()->findOrFail((int) $data['vessel_id']);
        $data['created_by_user_id'] = Auth::id();

        try {
            $block = app(CreateVesselBlockAction::class)($vessel, $data);
        } catch (ValidationException $exception) {
            throw $this->attachToForm($exception);
        }

        $this->warnAboutSoldDepartures($block);

        return $block;
    }

    /**
     * Tell the operator, and cancel nothing (§2.4, brief §5 rule 1).
     *
     * A maintenance window typed with the wrong month would otherwise silently
     * cancel a boat full of paying guests. The observer has already set
     * `is_blocked`; this is the part a person actually sees.
     */
    private function warnAboutSoldDepartures(VesselBlock $block): void
    {
        $affected = app(RecomputeDepartureBlockedFlags::class)($block);

        if ($affected->isEmpty()) {
            return;
        }

        Notification::make()
            ->warning()
            ->title(__('availability.block.validation.sold_departures_blocked', [
                'count' => (string) $affected->count(),
            ]))
            ->persistent()
            ->send();
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
