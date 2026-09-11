<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AnnouncementResource\Pages;

use App\Filament\Admin\Resources\AnnouncementResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * Editing an announcement changes it for everybody who has not closed it; it
 * does not bring it back for those who have. A notice that needs to reach
 * everyone again is a new announcement.
 */
class EditAnnouncement extends EditRecord
{
    protected static string $resource = AnnouncementResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
