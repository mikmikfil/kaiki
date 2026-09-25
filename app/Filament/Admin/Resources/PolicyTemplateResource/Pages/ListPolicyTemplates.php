<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PolicyTemplateResource\Pages;

use App\Filament\Admin\Resources\PolicyTemplateResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPolicyTemplates extends ListRecords
{
    protected static string $resource = PolicyTemplateResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
