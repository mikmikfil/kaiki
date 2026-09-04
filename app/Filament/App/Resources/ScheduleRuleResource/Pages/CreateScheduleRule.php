<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ScheduleRuleResource\Pages;

use App\Filament\App\Resources\ScheduleRuleResource;
use App\Models\ScheduleRule;
use Filament\Resources\Pages\CreateRecord;

class CreateScheduleRule extends CreateRecord
{
    use ConsumesWeekdays;

    protected static string $resource = ScheduleRuleResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): ScheduleRule
    {
        return $this->saveScheduleRule(new ScheduleRule, $data);
    }
}
