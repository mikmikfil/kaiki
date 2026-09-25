<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ScheduleRuleResource\Pages;

use App\Filament\App\Resources\ScheduleRuleResource;
use App\Filament\App\Support\ScheduleConflictNotice;
use App\Models\ScheduleRule;
use Filament\Resources\Pages\CreateRecord;

class CreateScheduleRule extends CreateRecord
{
    use ConsumesWeekdays;

    protected static string $resource = ScheduleRuleResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): ScheduleRule
    {
        $rule = $this->saveScheduleRule(new ScheduleRule, $data);

        // The boat may already be out on another trip at these hours. Said
        // after the save, because AVL-11 permits it — see the notice.
        ScheduleConflictNotice::sendFor($rule);

        return $rule;
    }
}
