<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ImportJobResource\Pages;

use App\Domain\Import\Actions\SaveImportMapping;
use App\Enums\BookingMode;
use App\Enums\ImportRowStatus;
use App\Enums\ImportRowType;
use App\Enums\ImportStatus;
use App\Enums\ProductCategory;
use App\Filament\App\Resources\ImportJobResource;
use App\Jobs\CommitImportJob;
use App\Models\ImportJob;
use App\Models\ImportJobRow;
use App\Models\Product;
use App\Models\Vessel;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * The dry run's review screen, and where an import is started or resumed
 * (SAA-14, SAA-15).
 *
 * Top to bottom: the choices (trips, passenger types, the booking cut-off
 * date), then every record the files held with what will happen to it — or what
 * did — and why. Saving the choices re-runs the verdicts at once, so the list
 * always shows the consequence of what is on the form.
 *
 * The form is read-only unless the import is reviewable: once it has been
 * committed, the mapping that produced its rows is history.
 */
class ReviewImport extends Page implements HasForms
{
    use InteractsWithForms;
    use InteractsWithRecord;

    protected static string $resource = ImportJobResource::class;

    protected static string $view = 'filament.app.imports.review';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(Gate::allows('view', $this->record), 403);

        $this->getForm('form')?->fill($this->state());
    }

    public function getTitle(): string|Htmlable
    {
        return __('imports.review.title');
    }

    public function getSubheading(): ?string
    {
        return $this->job()->status->label();
    }

    public function job(): ImportJob
    {
        /** @var ImportJob $job */
        $job = $this->record;

        return $job;
    }

    public function isBusy(): bool
    {
        return $this->job()->refresh()->status->isBusy();
    }

    public function isReviewable(): bool
    {
        return $this->job()->status->isReviewable() && Gate::allows('update', $this->job());
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema($this->schema())
            ->statePath('data')
            ->disabled(fn (): bool => ! $this->isReviewable());
    }

    public function save(): void
    {
        abort_unless($this->isReviewable(), 403);

        app(SaveImportMapping::class)($this->job(), $this->formState());

        Notification::make()->title(__('imports.actions.saved'))->success()->send();

        $this->getForm('form')?->fill($this->state());
    }

    /** @return array<string, mixed> */
    private function formState(): array
    {
        return (array) $this->getForm('form')?->getState();
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('commit')
                ->label(fn (): string => $this->job()->status === ImportStatus::Failed ? __('imports.actions.resume') : __('imports.actions.commit'))
                ->icon('heroicon-o-play')
                ->visible(fn (): bool => $this->isReviewable())
                ->requiresConfirmation()
                ->modalHeading(__('imports.actions.commit_heading'))
                ->modalDescription(fn (): string => (string) __('imports.actions.commit_body', [
                    'products' => $this->count(ImportRowType::Product, ImportRowStatus::Mapped),
                    'bookings' => $this->count(ImportRowType::Booking, ImportRowStatus::Mapped),
                ]))
                ->action(function (): void {
                    abort_unless($this->isReviewable(), 403);

                    // Whatever is on the form is what gets imported — a choice
                    // changed and not yet saved must not be silently ignored.
                    app(SaveImportMapping::class)($this->job(), $this->formState());

                    $this->job()->forceFill(['status' => ImportStatus::Running])->save();

                    CommitImportJob::dispatch((int) $this->job()->getKey());

                    Notification::make()->title(__('imports.actions.commit_queued'))->success()->send();
                }),
        ];
    }

    /**
     * Every row, grouped by type in commit order, for the log.
     *
     * @return array<string, Collection<int, ImportJobRow>>
     */
    public function groups(): array
    {
        $rows = $this->job()->rows()->orderBy('id')->get();
        $groups = [];

        foreach (ImportRowType::cases() as $type) {
            $inType = $rows->where('source_type', $type)->values();

            if ($inType->isNotEmpty()) {
                $groups[$type->value] = $inType;
            }
        }

        return $groups;
    }

    /** @param Collection<int, ImportJobRow> $rows */
    public function countsLine(Collection $rows): string
    {
        $by = static fn (ImportRowStatus $status): int => $rows->where('status', $status)->count();

        return (string) __('imports.review.counts', [
            'mapped' => $by(ImportRowStatus::Mapped),
            'skipped' => $by(ImportRowStatus::Skipped),
            'imported' => $by(ImportRowStatus::Imported),
            'failed' => $by(ImportRowStatus::Failed),
        ]);
    }

    private function count(ImportRowType $type, ImportRowStatus $status): int
    {
        return $this->job()->rows()->where('source_type', $type->value)->where('status', $status->value)->count();
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        $mapping = $this->job()->mapping;

        return [
            'products' => $mapping['products'] ?? [],
            'people_types' => $mapping['people_types'] ?? [],
            'bookings' => ['import_from' => $mapping['bookings']['import_from'] ?? null],
        ];
    }

    /** @return array<int, mixed> */
    private function schema(): array
    {
        $rows = $this->job()->rows()->orderBy('id')->get();
        $productOptions = Product::query()->orderByTranslation('title')->get()
            ->mapWithKeys(static fn (Product $p): array => [(int) $p->getKey() => (string) $p->title])->all();
        $vesselOptions = Vessel::query()->orderBy('name')->get()
            ->mapWithKeys(static fn (Vessel $v): array => [(int) $v->getKey() => (string) $v->name])->all();
        $modes = [
            BookingMode::PerSeat->value => BookingMode::PerSeat->label(),
            BookingMode::PerVessel->value => BookingMode::PerVessel->label(),
        ];

        $productFields = [];

        foreach ($rows->where('source_type', ImportRowType::Product) as $row) {
            $key = 'products.' . $row->source_id;

            $productFields[] = Fieldset::make($row->label())
                ->columns(4)
                ->schema([
                    Select::make("{$key}.action")
                        ->label(__('imports.review.field.action'))
                        ->options([
                            'create' => __('imports.review.action.create'),
                            'link' => __('imports.review.action.link'),
                            'skip' => __('imports.review.action.skip'),
                        ])
                        ->selectablePlaceholder(false)
                        ->live(),

                    Select::make("{$key}.target_product_id")
                        ->label(__('imports.review.field.target'))
                        ->options($productOptions)
                        ->searchable()
                        ->visible(static fn (Get $get): bool => $get("{$key}.action") === 'link'),

                    Select::make("{$key}.mode")
                        ->label(__('imports.review.field.mode'))
                        ->options($modes)
                        ->selectablePlaceholder(false)
                        ->visible(static fn (Get $get): bool => $get("{$key}.action") === 'create'),

                    Select::make("{$key}.category")
                        ->label(__('imports.review.field.category'))
                        ->options(ProductCategory::options())
                        ->selectablePlaceholder(false)
                        ->visible(static fn (Get $get): bool => $get("{$key}.action") === 'create'),

                    Select::make("{$key}.vessel_id")
                        ->label(__('imports.review.field.vessel'))
                        ->options($vesselOptions)
                        ->visible(static fn (Get $get): bool => $get("{$key}.action") === 'create'),
                ]);
        }

        $peopleFields = [];

        foreach ($rows->where('source_type', ImportRowType::PeopleType) as $row) {
            $key = 'people_types.' . $row->source_id;

            $peopleFields[] = Fieldset::make($row->label())
                ->columns(4)
                ->schema([
                    TextInput::make("{$key}.age_band_code")->label(__('imports.review.field.band_code'))->maxLength(32),
                    TextInput::make("{$key}.min_age")->label(__('imports.review.field.min_age'))->numeric()->minValue(0)->maxValue(120),
                    TextInput::make("{$key}.max_age")->label(__('imports.review.field.max_age'))->numeric()->minValue(0)->maxValue(120),
                    Toggle::make("{$key}.counts_toward_capacity")->label(__('imports.review.field.counts'))->inline(false),
                ]);
        }

        $sections = [];

        if ($productFields !== []) {
            $sections[] = Section::make(__('imports.review.products'))
                ->description(__('imports.review.products_help'))
                ->schema($productFields)
                ->collapsible();
        }

        if ($peopleFields !== []) {
            $sections[] = Section::make(__('imports.review.people_types'))
                ->description(__('imports.review.people_types_help'))
                ->schema($peopleFields)
                ->collapsible();
        }

        if ($rows->where('source_type', ImportRowType::Booking)->isNotEmpty()) {
            $sections[] = Section::make(__('imports.review.bookings'))
                ->description(__('imports.review.bookings_help'))
                ->schema([
                    // A calendar date, not an instant: kept out of the panel's
                    // timezone conversion for the reason the exports form gives.
                    DatePicker::make('bookings.import_from')
                        ->label(__('imports.review.field.import_from'))
                        ->timezone(config('app.timezone')),
                ]);
        }

        return $sections;
    }
}
