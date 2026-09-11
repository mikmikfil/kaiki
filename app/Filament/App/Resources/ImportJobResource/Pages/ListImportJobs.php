<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ImportJobResource\Pages;

use App\Domain\Import\Actions\StartImport;
use App\Filament\App\Resources\ImportJobResource;
use App\Support\Tenancy;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;

/**
 * The imports, and the one modal that starts one (SAA-13).
 *
 * Two uploads rather than one, because they are two different exports from two
 * different WordPress screens, and the help under each says which. Either may
 * be left out; the modal refuses only when both are.
 */
class ListImportJobs extends ListRecords
{
    protected static string $resource = ImportJobResource::class;

    public function getHeading(): string|Htmlable
    {
        return __('imports.title');
    }

    public function getSubheading(): ?string
    {
        return __('imports.subheading');
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('new')
                ->label(__('imports.actions.new'))
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(fn (): bool => ImportJobResource::canCreate())
                ->modalSubmitActionLabel(__('imports.actions.submit'))
                ->form([
                    FileUpload::make('wxr')
                        ->label(__('imports.upload.wxr'))
                        ->helperText(__('imports.upload.wxr_help'))
                        ->disk('local')
                        ->directory(fn (): string => 'imports/' . Tenancy::id())
                        ->visibility('private')
                        ->acceptedFileTypes(['text/xml', 'application/xml', 'application/rss+xml'])
                        ->maxSize(20480),

                    FileUpload::make('csv')
                        ->label(__('imports.upload.csv'))
                        ->helperText(__('imports.upload.csv_help'))
                        ->disk('local')
                        ->directory(fn (): string => 'imports/' . Tenancy::id())
                        ->visibility('private')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                        ->maxSize(20480),

                    Placeholder::make('privacy')
                        ->hiddenLabel()
                        ->content(__('imports.upload.privacy')),
                ])
                ->action(function (array $data): void {
                    $wxr = is_string($data['wxr'] ?? null) && $data['wxr'] !== '' ? $data['wxr'] : null;
                    $csv = is_string($data['csv'] ?? null) && $data['csv'] !== '' ? $data['csv'] : null;

                    if ($wxr === null && $csv === null) {
                        Notification::make()->title(__('imports.upload.one_required'))->danger()->send();

                        return;
                    }

                    $job = app(StartImport::class)('local', $wxr, $csv, Auth::id());

                    Notification::make()
                        ->title(__('imports.actions.queued_title'))
                        ->body(__('imports.actions.queued_body'))
                        ->success()
                        ->send();

                    $this->redirect(ImportJobResource::getUrl('review', ['record' => $job]));
                }),
        ];
    }
}
