<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\FaqResource\Pages;

use App\Filament\App\Resources\FaqResource;
use App\Filament\App\Support\TitledByRecord;
use App\Filament\Support\MoreActions;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFaq extends EditRecord
{
    use TitledByRecord;

    protected static string $resource = FaqResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return MoreActions::header([], [DeleteAction::make()]);
    }
}
