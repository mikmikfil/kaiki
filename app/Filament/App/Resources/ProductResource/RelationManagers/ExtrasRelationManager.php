<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ProductResource\RelationManagers;

use App\Domain\Catalog\Actions\SaveExtra;
use App\Enums\ExtraPricing;
use App\Filament\App\Resources\ProductResource;
use App\Filament\Forms\MoneyInput;
use App\Filament\Forms\TranslatableInput;
use App\Models\Extra;
use App\Models\Product;
use App\Support\Format\MoneyFormatter;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Πρόσθετα» on the trip (product owner, 2026-09-17).
 *
 * ## Why this exists when the rest already did
 *
 * Extras have had a model, a pivot, a writer ({@see SaveExtra}), a price
 * engine and a step in the booking widget since M1 — and no screen. An
 * operator could not add one, so the widget's extras step never appeared for
 * anybody. This is that screen, where the operator already is: the trip.
 *
 * ## One list, free or paid
 *
 * Each item is either **free** — an amenity the trip includes, listed on the
 * trip page with «Περιλαμβάνονται» and never offered for sale — or **paid**,
 * per person, per booking, or «κατόπιν αιτήματος» with no price. A paid one
 * can be required, which puts it on every booking.
 *
 * ## An extra made here belongs to this trip
 *
 * `is_tenant_wide` is false and the pivot row is this product's, so the item
 * appears here and nowhere else. Removing it detaches it; the extra itself is
 * soft-deleted only when no other trip still offers it, so a booking that
 * already recorded it keeps its name.
 */
class ExtrasRelationManager extends RelationManager
{
    protected static string $relationship = 'extras';

    protected static ?string $icon = 'heroicon-o-sparkles';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('catalog.extra.on_product.title');
    }

    public function form(Form $form): Form
    {
        return $form->schema(static::fields())->columns(2);
    }

    /**
     * An extra's fields, shared with the new-trip wizard's «Πρόσθετα»
     * (2026-09-24), so the two ask the same thing in the same words.
     *
     * @return list<Component>
     */
    public static function fields(): array
    {
        return [
            TranslatableInput::text(
                'name',
                __('catalog.extra.on_product.name.label'),
                __('catalog.extra.on_product.name.help'),
                maxLength: 120,
            ),

            Radio::make('kind')
                ->label(__('catalog.extra.on_product.kind.label'))
                ->options([
                    'free' => __('catalog.extra.on_product.kind.free'),
                    'paid' => __('catalog.extra.on_product.kind.paid'),
                ])
                ->default('paid')
                ->inline()
                ->required()
                ->live()
                ->columnSpanFull(),

            Radio::make('pricing_type')
                ->label(__('catalog.extra.on_product.pricing_type.label'))
                ->options([
                    ExtraPricing::PerPerson->value => ExtraPricing::PerPerson->label(),
                    ExtraPricing::PerBooking->value => ExtraPricing::PerBooking->label(),
                    ExtraPricing::OnRequest->value => ExtraPricing::OnRequest->label(),
                ])
                ->default(ExtraPricing::PerPerson->value)
                ->inline()
                ->required(static fn (Get $get): bool => $get('kind') === 'paid')
                ->visible(static fn (Get $get): bool => $get('kind') === 'paid')
                ->live()
                ->columnSpanFull(),

            MoneyInput::make(
                'price_cents',
                __('catalog.extra.on_product.price.label'),
                __('catalog.extra.on_product.price.help'),
            )
                ->required(static fn (Get $get): bool => $get('kind') === 'paid' && $get('pricing_type') !== ExtraPricing::OnRequest->value)
                ->visible(static fn (Get $get): bool => $get('kind') === 'paid' && $get('pricing_type') !== ExtraPricing::OnRequest->value),

            /*
             * **Ο ΦΠΑ της γραμμής, δίπλα στην τιμή της** (Mike, 23/9).
             *
             * Η στήλη `extras.vat_rate_id` υπήρχε από την αρχή, το μοντέλο τη
             * δηλώνει *«overrides the product's rate for this line»* και φτάνει
             * μέχρι το `OfferedExtra` — αλλά **καμία φόρμα δεν τη ζητούσε**,
             * οπότε ένα γεύμα πάνω στο σκάφος δεν μπορούσε να πάρει δικό του
             * συντελεστή. Είναι ακριβώς το παράδειγμα που δίνει το
             * `docs/data-model.md` §2.3 για να δικαιολογήσει τη στήλη: η
             * κρουαζιέρα είναι μεταφορά επιβατών, το γεύμα είναι εστίαση.
             *
             * **Κενό σημαίνει «ό,τι λέει η εκδρομή»**, γι' αυτό και το
             * `default(null)` — σε αντίθεση με τη φόρμα της εκδρομής, που
             * προσυμπληρώνει την απάντηση του λογαριασμού. Η εκδρομή απαντά μια
             * φορά· το extra μιλάει μόνο όταν διαφέρει, και έτσι ακολουθεί την
             * εκδρομή αν εκείνη αλλάξει αργότερα.
             */
            ProductResource::vatRateSelect()
                ->label(__('catalog.extra.on_product.vat_rate.label'))
                ->helperText(__('catalog.extra.on_product.vat_rate.help'))
                ->default(null)
                ->visible(static fn (Get $get): bool => $get('kind') === 'paid'),

            Toggle::make('is_required')
                ->label(__('catalog.extra.on_product.is_required.label'))
                ->helperText(__('catalog.extra.on_product.is_required.help'))
                ->default(false)
                ->visible(static fn (Get $get): bool => $get('kind') === 'paid'),

            Toggle::make('is_active')
                ->label(__('catalog.extra.on_product.is_active.label'))
                ->default(true),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('catalog.extra.on_product.title'))
            ->description(__('catalog.extra.on_product.help'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('catalog.extra.on_product.name.label'))
                    ->formatStateUsing(static fn (Extra $record): string => (string) $record->name),

                TextColumn::make('pricing_type')
                    ->label(__('catalog.extra.on_product.pricing_type.label'))
                    ->badge()
                    ->color(static fn (ExtraPricing $state): string => $state === ExtraPricing::Free ? 'success' : 'gray')
                    ->formatStateUsing(static fn (ExtraPricing $state): string => $state->label()),

                TextColumn::make('price_cents')
                    ->label(__('catalog.extra.on_product.price.label'))
                    ->formatStateUsing(static fn (?int $state): string => $state === null ? '—' : MoneyFormatter::format($state))
                    ->placeholder('—'),

                IconColumn::make('is_required')
                    ->label(__('catalog.extra.on_product.is_required.label'))
                    ->boolean(),

                IconColumn::make('is_active')
                    ->label(__('catalog.extra.on_product.is_active.label'))
                    ->boolean(),
            ])
            ->emptyStateHeading(__('catalog.extra.on_product.empty'))
            ->emptyStateDescription(__('catalog.extra.on_product.empty_help'))
            ->headerActions([
                CreateAction::make()
                    ->label(__('catalog.extra.on_product.add'))
                    ->modalHeading(__('catalog.extra.on_product.add'))
                    ->using(fn (array $data): Extra => $this->persist(new Extra, $data)),
            ])
            ->actions([
                EditAction::make()
                    ->mutateRecordDataUsing(static function (array $data, Extra $record): array {
                        $data['kind'] = $record->pricing_type === ExtraPricing::Free ? 'free' : 'paid';
                        $data['name'] = $record->getTranslations('name');

                        return $data;
                    })
                    ->using(fn (Extra $record, array $data): Extra => $this->persist($record, $data)),
                DeleteAction::make()
                    ->using(fn (Extra $record): bool => $this->remove($record)),
            ]);
    }

    /**
     * Free or paid into the one column {@see SaveExtra} understands. Shared
     * with the new-trip wizard.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function attributesFrom(array $data): array
    {
        $free = ($data['kind'] ?? 'paid') === 'free';
        unset($data['kind']);

        $data['pricing_type'] = $free ? ExtraPricing::Free->value : ($data['pricing_type'] ?? ExtraPricing::PerPerson->value);

        if ($free || $data['pricing_type'] === ExtraPricing::OnRequest->value || ($data['price_cents'] ?? '') === '') {
            $data['price_cents'] = null;
        }

        // The form's own defaults, for a row that never showed the switches.
        $data['is_required'] = ! $free && (bool) ($data['is_required'] ?? false);
        $data['is_active'] = (bool) ($data['is_active'] ?? true);

        $data['is_tenant_wide'] = false;

        return $data;
    }

    /** @param  array<string, mixed>  $data */
    private function persist(Extra $record, array $data): Extra
    {
        $data = static::attributesFrom($data);

        /** @var Product $product */
        $product = $this->getOwnerRecord();

        try {
            return app(SaveExtra::class)($record, $data, [$product->getKey() => []]);
        } catch (ValidationException $exception) {
            $messages = [];

            foreach ($exception->errors() as $key => $bag) {
                $messages['mountedTableActionsData.0.' . $key] = $bag;
            }

            throw ValidationException::withMessages($messages);
        }
    }

    /** Off this trip; gone altogether only when no other trip offers it. */
    private function remove(Extra $record): bool
    {
        /** @var Product $product */
        $product = $this->getOwnerRecord();

        return DB::transaction(static function () use ($record, $product): bool {
            $record->products()->detach($product->getKey());

            if (! $record->is_tenant_wide && ! $record->products()->exists()) {
                $record->delete();
            }

            return true;
        });
    }
}
