<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ScheduleRuleResource\Pages;

use App\Filament\App\Resources\ScheduleRuleResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListScheduleRules extends ListRecords
{
    protected static string $resource = ScheduleRuleResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
