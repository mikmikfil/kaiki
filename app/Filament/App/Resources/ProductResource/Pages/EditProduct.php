<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ProductResource\Pages;

use App\Domain\Catalog\Actions\SaveProduct;
use App\Enums\ProductStatus;
use App\Filament\App\Resources\ProductResource;
use App\Filament\Support\MoreActions;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class EditProduct extends EditRecord
{
    use ConsumesAgeBands;
    use ManagesPriceTable;

    protected static string $resource = ProductResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->loadPriceTable();
    }

    /**
     * The trip's own name, not «Επεξεργασία: Εκδρομή» (rule Ε of the form
     * mockup, 2026-09-24): with several trips open in tabs, the model's name
     * said nothing about which one this was.
     */
    public function getTitle(): string
    {
        $title = trim((string) $this->getRecord()->getAttribute('title'));

        return $title !== '' ? $title : parent::getTitle();
    }

    /** The name, and beside it whether it is on sale — the same pill as the list. */
    public function getHeading(): string|Htmlable
    {
        $status = $this->getRecord()->getAttribute('status');

        if (! $status instanceof ProductStatus) {
            return $this->getTitle();
        }

        return new HtmlString(
            '<span class="ka-title-with-state">' . e($this->getTitle()) . ' '
            . Blade::render('<x-filament::badge :color="$color" size="lg">{{ $label }}</x-filament::badge>', [
                'color' => match ($status) {
                    ProductStatus::Draft => 'warning',
                    ProductStatus::Active => 'success',
                    ProductStatus::Inactive, ProductStatus::Archived => 'gray',
                },
                'label' => __('enums.product_status.' . $status->value . '.label'),
            ])
            . '</span>',
        );
    }

    /**
     * The bands may have changed with the trip, so the table is rebuilt, unless
     * it holds typed prices that are not saved yet: those are kept, not lost.
     */
    protected function afterSave(): void
    {
        if (! $this->priceDirty) {
            $this->loadPriceTable();
        }
    }

    /** @return array<int, Action|ActionGroup> */
    protected function getHeaderActions(): array
    {
        return MoreActions::header([
            // The guest's own page, for the trip being edited. Absent while it
            // is a draft: there is nothing published to look at.
            Action::make('preview')
                ->label(__('catalog.product.table.preview'))
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->url(fn (): ?string => ProductResource::previewUrl($this->product()), shouldOpenInNewTab: true)
                ->visible(fn (): bool => ProductResource::previewUrl($this->product()) !== null),
        ], [
            Action::make('archive')
                ->label(__('catalog.product.status_actions.archive'))
                ->icon('heroicon-o-archive-box')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(__('catalog.product.status_actions.archive_confirm'))
                ->visible(fn (): bool => $this->product()->status !== ProductStatus::Archived)
                ->action(fn () => $this->setStatusOnly(ProductStatus::Archived, 'archived')),
            Action::make('unarchive')
                ->label(__('catalog.product.status_actions.unarchive'))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->visible(fn (): bool => $this->product()->status === ProductStatus::Archived)
                ->action(fn () => $this->setStatusOnly(ProductStatus::Draft, 'unarchived')),
            DeleteAction::make(),
            RestoreAction::make(),
        ]);
    }

    /**
     * The buttons under the form set the status (product owner, 2026-09-17).
     *
     * A draft is saved as a draft or published; a trip on sale is saved or
     * taken off sale; one off sale is saved or put back. Publishing saves the
     * form with `active`, so `SaveProduct` judges the checklist on exactly what
     * was submitted. A refusal keeps the edits, unpublished, and says what is
     * missing.
     *
     * @return array<int, Action>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction()
                ->label(fn (): string => $this->product()->status === ProductStatus::Draft
                    ? __('catalog.product.status_actions.save_draft')
                    : __('catalog.product.status_actions.save'))
                ->color(fn (): string => $this->product()->status === ProductStatus::Draft ? 'gray' : 'primary')
                /*
                 * **The button has to name its form** (2026-09-22).
                 *
                 * «Αποθήκευση» is a plain `type="submit"`, which submits the
                 * form it sits inside. Since the price lists, the extras and
                 * the schedule rules moved into the tabs (2026-09-21) this page
                 * renders relation managers — each carrying a `<form>` of its
                 * own — inside the trip's form, and a nested `<form>` ends the
                 * outer one as far as the browser is concerned. The save
                 * button, which comes after them, was left inside no form at
                 * all: clicking it issued **no request**. No error, no
                 * notification, and the operator's edit simply gone on the next
                 * load. It was found by driving the real form: the escort
                 * toggle would not stay on, and neither would anything else.
                 *
                 * `formId()` renders `form="form"`, which ties a button to a
                 * form by id wherever it sits in the document. The create page
                 * never had this — it has no relation managers to nest — and
                 * nor do the vessel and port forms, which is why it looked at
                 * first like something about one trip rather than about this
                 * page. «Δημοσίευση» below was never affected: it is a Livewire
                 * action and does not submit anything.
                 */
                ->formId('form'),
            Action::make('publish')
                ->label(fn (): string => $this->product()->status === ProductStatus::Inactive
                    ? __('catalog.product.status_actions.republish')
                    : __('catalog.product.status_actions.publish'))
                ->visible(fn (): bool => in_array($this->product()->status, [ProductStatus::Draft, ProductStatus::Inactive], true))
                ->action(fn () => $this->saveWithStatus(ProductStatus::Active, 'published')),
            Action::make('unpublish')
                ->label(__('catalog.product.status_actions.unpublish'))
                ->color('gray')
                ->visible(fn (): bool => $this->product()->status === ProductStatus::Active)
                ->action(fn () => $this->saveWithStatus(ProductStatus::Inactive, 'unpublished')),
            $this->getCancelFormAction(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Product $record */
        $record = $this->getRecord();

        return $this->fillTripPageContent($this->fillAgeBands($data, $record), $record);
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->saveProductWithBands($record, $data);
    }

    private function product(): Product
    {
        /** @var Product */
        return $this->getRecord();
    }

    /** Save the whole form with a new status; say why when publishing is refused. */
    private function saveWithStatus(ProductStatus $status, string $done): void
    {
        $previous = $this->data['status'] ?? null;
        $this->data['status'] = $status->value;

        try {
            $this->save(shouldSendSavedNotification: false);
        } catch (ValidationException $exception) {
            $this->data['status'] = $previous;

            // The status field is hidden, so its errors (the checklist lines)
            // would otherwise be shown nowhere.
            $reasons = $exception->errors()['data.status'] ?? [];

            if ($reasons !== []) {
                Notification::make()
                    ->danger()
                    ->title(__('catalog.product.status_actions.not_published'))
                    ->body(implode('<br>', array_map('e', $reasons)))
                    ->persistent()
                    ->send();
            }

            throw $exception;
        }

        // Another field refused the save (Filament halted without throwing).
        if ($this->product()->status !== $status) {
            $this->data['status'] = $previous;

            return;
        }

        Notification::make()
            ->success()
            ->title(__("catalog.product.status_actions.{$done}"))
            ->send();
    }

    /** Archive, or bring back as a draft, without touching unsaved edits. Never refused. */
    private function setStatusOnly(ProductStatus $status, string $done): void
    {
        app(SaveProduct::class)($this->product(), ['status' => $status->value]);

        $this->product()->refresh();
        $this->data['status'] = $status->value;

        Notification::make()
            ->success()
            ->title(__("catalog.product.status_actions.{$done}"))
            ->send();
    }
}
