<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ProductResource\Pages;

use App\Enums\BookingMode;
use App\Enums\ProductStatus;
use App\Filament\App\Resources\ProductResource;
use App\Models\Product;
use App\Models\Vessel;
use Filament\Actions\Action;
use Filament\Forms\Components\View as ViewField;
use Filament\Forms\Form;
use Filament\Resources\Pages\CreateRecord;

/**
 * «Νέα εκδρομή»: the «Βασικά» tab, then the trip's own page.
 *
 * ## One form, not two (Mike, 25/9)
 *
 * *«Πιστεύω έχει γίνει μπέρδεμα με την πρώτη δημιουργία εκδρομής και την
 * επεξεργασία. Ας τα κάνουμε ίδια τα στοιχεία που ζητούνται, τη δομή.»*
 *
 * Until today creating a trip was a five-step wizard of its own: the edit
 * page's sections, rebuilt as steps, with its own fields for everything the
 * edit page saves through relation managers — periods, a price grid, extras,
 * schedules — and its own code to write them. Two forms drifted, and the
 * drift cost an operator their prices: the wizard read its price grid by the
 * wrong keys and made the trip with no price list at all (same day).
 *
 * The wizard existed because the edit page's tabs were inert until the trip
 * existed (22/9). So the trip now exists first: this page asks what a trip
 * cannot be saved without — the «Βασικά» tab, exactly as the edit page shows
 * it — saves it as a draft, and opens the edit page on «Πότε φεύγει». There,
 * while the trip is a draft, every tab ends in «← Πίσω» and «Επόμενο →», so
 * it still reads as steps; and every field, list and save is the edit page's
 * own. What an operator types at creation is what they see when editing,
 * because it is the same screen.
 */
class CreateProduct extends CreateRecord
{
    use ConsumesAgeBands;

    protected static string $resource = ProductResource::class;

    /**
     * No «create another»: it made a second click on a slow connection look
     * like the first one had not worked.
     */
    protected static bool $canCreateAnother = false;

    /** «Νέα εκδρομή», not Filament's «Νέα εγγραφή: Εκδρομή» (2026-09-24). */
    public function getTitle(): string
    {
        return __('catalog.product.wizard.title');
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            // The same one switch for both languages as the edit page.
            ViewField::make('filament.app.form-locale-switch')
                ->columnSpanFull(),

            ...ProductResource::basicsSections(),
        ]);
    }

    /** «Συνέχεια», because the trip is not finished: the next tab is. */
    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label(__('catalog.product.wizard.continue'));
    }

    /** The trip's own page, on the tab that comes after «Βασικά». */
    protected function getRedirectUrl(): string
    {
        return ProductResource::getUrl('edit', ['record' => $this->getRecord()])
            . '?tab=' . ProductResource::tabQueryKey('when');
    }

    /**
     * A draft with what the edit page would otherwise show empty.
     *
     * The columns a trip cannot be stored without get the defaults the wizard
     * used to offer — the boat's certificate for «Μέγιστα άτομα», three hours,
     * half an hour's check-in — and a per-seat trip gets the usual three age
     * bands, so «Τιμές» opens with rows to price rather than a blank list.
     * All of it is on the next tabs to change.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Product
    {
        $data['status'] = ProductStatus::Draft->value;

        if (($data['max_pax'] ?? null) === null || $data['max_pax'] === '') {
            $data['max_pax'] = (int) (Vessel::query()->whereKey($data['vessel_id'] ?? null)->value('capacity_max') ?? 1);
        }

        $data['duration_minutes'] ??= 180;
        $data['check_in_offset_minutes'] ??= 30;
        $data['min_pax'] ??= 0;
        $data['min_booking_pax'] ??= 1;

        if (($data['mode'] ?? null) === BookingMode::PerSeat->value && ($data['age_bands'] ?? []) === []) {
            $data['age_bands'] = ProductResource::defaultAgeBands();
        }

        return $this->saveProductWithBands(new Product, $data);
    }
}
