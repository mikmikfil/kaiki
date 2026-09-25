<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ScheduleRuleResource\Pages;

use App\Filament\App\Resources\ScheduleRuleResource;
use App\Filament\App\Support\VesselMoveNotice;
use App\Filament\Support\MoreActions;
use App\Models\ScheduleRule;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditScheduleRule extends EditRecord
{
    use ConsumesWeekdays;

    protected static string $resource = ScheduleRuleResource::class;

    /**
     * Delete removes the rule and **not** its departures (§2.3).
     *
     * They may already hold bookings. "Also cancel future empty departures" is
     * a separate explicit action, and it lands with #27 — the departures table
     * does not exist yet.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return MoreActions::header([], [DeleteAction::make()]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var ScheduleRule $record */
        $record = $this->getRecord();

        return $this->fillWeekdays($data, $record);
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var ScheduleRule $record */
        $vesselBefore = $record->effectiveVesselId();

        $saved = $this->saveScheduleRule($record, $data);

        // A new boat: its unsold sailings follow, the sold ones are listed.
        VesselMoveNotice::afterRuleSave($saved, $vesselBefore);

        return $saved;
    }
}
