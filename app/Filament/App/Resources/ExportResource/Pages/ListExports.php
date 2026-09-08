<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ExportResource\Pages;

use App\Domain\Operations\Actions\RequestExport;
use App\Enums\BookingStatus;
use App\Enums\ExportDateBasis;
use App\Enums\ExportType;
use App\Filament\App\Resources\ExportResource;
use App\Models\Product;
use App\Models\Vessel;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * The list, and the one modal that starts an export (spec OPS-17, OPS-18).
 *
 * ## The date basis is on the form, above the dates
 *
 * It is the field an operator would skip if it were below them, and it is the
 * one that decides which rows are in the file. A booking made in June for a
 * trip in August and paid in July belongs to three different months; picking
 * one silently is how a file gets reconciled once and never trusted again.
 *
 * The options are live-filtered by the export type, because a passenger list
 * windowed on *"the date the money arrived"* would run, produce rows, and
 * answer a question nobody asked.
 *
 * ## Two promises are made on the form rather than in the file
 *
 * That document numbers are never included (OPS-10) and that the link expires
 * (OPS-18). Both are properties an operator cannot see by looking at the
 * result — the absence of a column is invisible, and a link's expiry only
 * becomes visible on the day it stops working.
 */
class ListExports extends ListRecords
{
    protected static string $resource = ExportResource::class;

    public function getHeading(): string
    {
        return __('exports.title');
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('new')
                ->label(__('exports.action.new'))
                ->icon('heroicon-o-plus')
                // The resource's own policy, so read-only mode and the crew
                // refusal are both answered in one place.
                ->visible(fn (): bool => $this->canCreateExport())
                ->modalSubmitActionLabel(__('exports.action.submit'))
                ->form([
                    Select::make('type')
                        ->label(__('exports.action.type'))
                        ->options(ExportType::options())
                        ->default(ExportType::Bookings->value)
                        ->required()
                        // The basis options depend on it, so the field below
                        // has to be told when this changes.
                        ->live(),

                    Select::make('date_basis')
                        ->label(__('exports.action.date_basis'))
                        ->options(fn (Get $get): array => self::basisOptions($get('type')))
                        ->default(ExportType::Bookings->defaultDateBasis()->value)
                        ->required(),

                    /*
                     * Plain calendar dates, deliberately outside the panel's
                     * global timezone conversion.
                     *
                     * `AppPanelProvider::boot()` gives every picker the tenant's
                     * timezone, which is right for a departure time — CNV-2
                     * stores an instant in UTC and shows it in Athens. A window
                     * is not an instant. Left converted, "1 June" arrives here
                     * as `2026-05-31 21:00` and the export silently covers a
                     * different month than the one on the form.
                     *
                     * The timezone still applies; it is applied **once**, in
                     * `ExportRows::startOfDayUtc()`, where the tenant is known
                     * and where the column being compared is known too.
                     */
                    DatePicker::make('from')
                        ->label(__('exports.action.from'))
                        ->timezone(config('app.timezone')),

                    DatePicker::make('to')
                        ->label(__('exports.action.to'))
                        ->timezone(config('app.timezone')),

                    CheckboxList::make('statuses')
                        ->label(__('exports.action.statuses'))
                        ->helperText(__('exports.action.statuses_help'))
                        ->options(self::statusOptions())
                        ->columns(3)
                        // Deliberately not defaulted to everything ticked. An
                        // empty selection means "all of them", and pre-ticking
                        // nine boxes invites somebody to untick one by accident
                        // and never notice the rows it removed.
                        ->visible(fn (Get $get): bool => $get('type') === ExportType::Bookings->value),

                    Select::make('product_uuid')
                        ->label(__('exports.action.product'))
                        ->options(fn (): array => self::productOptions())
                        ->searchable()
                        ->placeholder(__('exports.action.all')),

                    Select::make('vessel_uuid')
                        ->label(__('exports.action.vessel'))
                        ->options(fn (): array => self::vesselOptions())
                        ->searchable()
                        ->placeholder(__('exports.action.all')),

                    Placeholder::make('notice')
                        ->hiddenLabel()
                        ->content(fn (): string => implode(' ', [
                            __('exports.notice.no_documents'),
                            __('exports.notice.no_test'),
                            __('exports.notice.expiry', ['hours' => ExportResource::ttlHours()]),
                        ])),
                ])
                ->action(function (array $data): void {
                    $type = ExportType::from((string) $data['type']);

                    app(RequestExport::class)(
                        type: $type,
                        basis: ExportDateBasis::tryFrom((string) ($data['date_basis'] ?? '')),
                        from: self::date($data['from'] ?? null),
                        to: self::date($data['to'] ?? null),
                        filters: [
                            'statuses' => $data['statuses'] ?? [],
                            'product_uuid' => $data['product_uuid'] ?? null,
                            'vessel_uuid' => $data['vessel_uuid'] ?? null,
                        ],
                        userId: Auth::id(),
                    );

                    Notification::make()
                        ->title(__('exports.action.queued_title'))
                        ->body(__('exports.action.queued_body'))
                        ->success()
                        ->send();
                }),
        ];
    }

    private function canCreateExport(): bool
    {
        return ExportResource::canCreate();
    }

    /**
     * The bases this export type can answer.
     *
     * @return array<string, string>
     */
    private static function basisOptions(mixed $type): array
    {
        $exportType = is_string($type) ? ExportType::tryFrom($type) : null;
        $exportType ??= ExportType::Bookings;

        $options = [];

        foreach ($exportType->dateBases() as $basis) {
            $options[$basis->value] = $basis->label();
        }

        return $options;
    }

    /**
     * The statuses an export can be narrowed to.
     *
     * `draft` and `expired` are absent rather than unticked: the export
     * excludes them unconditionally — a hold is not a booking — so offering
     * them would be offering a filter that does nothing.
     *
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        $options = [];

        foreach (BookingStatus::cases() as $status) {
            if ($status === BookingStatus::Draft || $status === BookingStatus::Expired) {
                continue;
            }

            $options[$status->value] = $status->label();
        }

        return $options;
    }

    /** @return array<string, string> */
    private static function productOptions(): array
    {
        return Product::query()
            // The companion column for the current locale (ADR-0008), not the
            // JSON one — ordering by a JSON column sorts by its serialised
            // bytes, which puts every Greek title after every English one.
            ->orderByTranslation('title')
            ->get()
            ->mapWithKeys(static fn (Product $product): array => [
                (string) $product->uuid => (string) $product->title,
            ])
            ->all();
    }

    /** @return array<string, string> */
    private static function vesselOptions(): array
    {
        return Vessel::query()
            ->orderBy('name')
            ->get()
            ->mapWithKeys(static fn (Vessel $vessel): array => [
                (string) $vessel->uuid => (string) $vessel->name,
            ])
            ->all();
    }

    private static function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return Carbon::parse($value);
    }
}
