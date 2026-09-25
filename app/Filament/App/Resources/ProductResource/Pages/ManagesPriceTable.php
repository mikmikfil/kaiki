<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ProductResource\Pages;

use App\Domain\Catalog\Actions\SaveSeason;
use App\Domain\Pricing\Actions\SavePeriodTerms;
use App\Domain\Pricing\Actions\SavePriceTable;
use App\Domain\Pricing\Support\PriceTable;
use App\Enums\BookingMode;
use App\Enums\DepositType;
use App\Filament\Forms\MoneyInput;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Season;
use App\Support\Format\MoneyFormatter;
use App\Support\Tenancy;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Prices by group and period on the trip's edit page (product owner,
 * 2026-09-17; the flow of `docs/mockups/pricing-flow.html` since 2026-09-24).
 *
 * ## Groups, then periods, then the table
 *
 * The groups are the form's «Ομάδες επιβατών». The periods are ticks — the
 * operator's own, shared by all their trips, plus «Όλο τον χρόνο», which is
 * always on — and a new one can be made here without leaving the page. The
 * table has a row per group and a column per ticked period, and is saved with
 * the deposit and deadlines below it on one button.
 *
 * ## Its own state and its own save button
 *
 * The cells, ticks and terms are page properties rather than fields of the trip
 * form. The form saves the trip and its groups; this saves the plans. So the
 * table says when it has unsaved changes, and is rebuilt after the trip is saved.
 *
 * ## Every price is typed
 *
 * No quick buttons (Mike, 2026-09-24: *«ας το βάζουν κατευθείαν στον πίνακα την
 * τιμή που θέλουν»*). A cell is the price, in euros.
 */
trait ManagesPriceTable
{
    /** @var array<string, array<string, string|null>> row key => column key => euros as typed */
    public array $priceCells = [];

    /** @var list<int> the ticked periods */
    public array $priceSeasonIds = [];

    /**
     * The trip's deposit and deadlines, as typed; the fixed deposit in euros.
     *
     * @var array{deposit_type: string, deposit_percent: int|string|null, deposit_fixed: string|null, balance_due_days_before_departure: int|string|null, min_lead_time_hours: int|string|null, max_advance_days: int|string|null}
     */
    public array $priceTerms = [
        'deposit_type' => 'none',
        'deposit_percent' => null,
        'deposit_fixed' => null,
        'balance_due_days_before_departure' => null,
        'min_lead_time_hours' => 0,
        'max_advance_days' => null,
    ];

    /** @var array{name: string, from: string, to: string} «Νέα περίοδος», before it is added */
    public array $priceNewPeriod = ['name' => '', 'from' => '', 'to' => ''];

    public bool $priceNewPeriodOpen = false;

    /** @var array<string, int> row key => how many, for «Τι πληρώνει ο επισκέπτης» */
    public array $pricePax = [];

    public string $pricePreviewColumn = '';

    public bool $priceDirty = false;

    /** Build everything from what is saved. Anything typed and unsaved is lost. */
    public function loadPriceTable(): void
    {
        $saved = PriceTable::for($this->priceProduct());

        $this->priceSeasonIds = array_values(array_map(
            static fn (array $period): int => $period['id'],
            array_filter($saved->periods, static fn (array $period): bool => $period['ticked']),
        ));

        $this->priceCells = [];
        $this->fillPriceCells($saved);

        $default = $this->priceProduct()->ratePlans()->whereNull('season_id')->first();
        $terms = $default instanceof RatePlan ? SavePriceTable::termsOf($default) : SavePriceTable::noTerms();

        $this->priceTerms = [
            'deposit_type' => (string) ($terms['deposit_type'] ?? DepositType::None->value),
            'deposit_percent' => $terms['deposit_percent'],
            'deposit_fixed' => self::priceText($terms['deposit_fixed_cents'] ?? null),
            'balance_due_days_before_departure' => $terms['balance_due_days_before_departure'],
            'min_lead_time_hours' => $terms['min_lead_time_hours'] ?? 0,
            'max_advance_days' => $terms['max_advance_days'],
        ];

        $this->pricePreviewColumn = $saved->columns[0]['key'] ?? '';
        $this->priceDirty = false;
    }

    /**
     * «32,50», not «32.50»: the way a Greek operator writes an amount
     * (2026-09-17). Saving already accepts either.
     */
    public static function priceText(?int $cents): ?string
    {
        $decimal = MoneyInput::toDecimal($cents);

        return $decimal === null ? null : str_replace('.', ',', $decimal);
    }

    /** How many cells of the table as ticked are still empty. */
    public function missingPriceCount(): int
    {
        $missing = 0;

        foreach ($this->priceTable()->columns as $column) {
            foreach ($this->priceCells as $cells) {
                if (trim((string) ($cells[$column['key']] ?? '')) === '') {
                    $missing++;
                }
            }
        }

        return $missing;
    }

    /** The table as ticked on this page, saved or not. */
    public function priceTable(): PriceTable
    {
        return PriceTable::for($this->priceProduct(), $this->priceSeasonIds);
    }

    /** Is there a table to show: a saved per-seat trip that has groups? */
    public function hasPriceTable(): bool
    {
        $product = $this->priceProduct();

        return $product->mode === BookingMode::PerSeat && $product->ageBands()->exists();
    }

    /** A period ticked or unticked: its column comes or goes, typed prices kept. */
    public function togglePriceSeason(int $seasonId): void
    {
        $this->priceSeasonIds = in_array($seasonId, $this->priceSeasonIds, true)
            ? array_values(array_diff($this->priceSeasonIds, [$seasonId]))
            : [...$this->priceSeasonIds, $seasonId];

        $this->fillPriceCells($this->priceTable());
        $this->priceDirty = true;
    }

    /**
     * «Νέα περίοδος», made here and ticked at once. Periods are the operator's
     * and shared by their trips; the full editor stays at «Περίοδοι».
     *
     * A new period outranks the ones it overlaps: made from inside a trip, it
     * is the exception to them, and two periods with equal priority on the
     * same day would be refused anyway.
     */
    public function addPricePeriod(): void
    {
        $this->authorizeAccess();

        $name = trim($this->priceNewPeriod['name']);
        $from = trim($this->priceNewPeriod['from']);
        $to = trim($this->priceNewPeriod['to']);

        $errors = [];

        if ($name === '') {
            $errors['priceNewPeriod.name'] = __('pricing.periods.new.name_required');
        }

        if ($from === '' || $to === '' || Carbon::parse($to)->lt(Carbon::parse($from))) {
            $errors['priceNewPeriod.to'] = __('pricing.periods.new.dates_required');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        try {
            $season = app(SaveSeason::class)(new Season, [
                'name' => [app()->getLocale() => $name],
                'priority' => (int) Season::query()->max('priority') + 1,
                'is_active' => true,
            ], [['starts_on' => $from, 'ends_on' => $to]]);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                'priceNewPeriod.to' => array_merge(...array_values($exception->errors())),
            ]);
        }

        $this->priceNewPeriod = ['name' => '', 'from' => '', 'to' => ''];
        $this->priceNewPeriodOpen = false;
        $this->togglePriceSeason((int) $season->getKey());
    }

    /** Livewire's hook for a typed cell: `priceCells.b12.p5`. */
    public function updatedPriceCells(): void
    {
        $this->priceDirty = true;
    }

    public function updatedPriceTerms(): void
    {
        $this->priceDirty = true;
    }

    public function changePricePax(string $rowKey, int $by): void
    {
        $this->pricePax[$rowKey] = max(0, min(99, ($this->pricePax[$rowKey] ?? 0) + $by));
    }

    /** «Τι πληρώνει ο επισκέπτης», from the cells as typed, saved or not. */
    public function priceGuestTotal(): string
    {
        $total = 0;

        foreach ($this->pricePax as $rowKey => $count) {
            $cents = MoneyInput::toCents($this->priceCells[$rowKey][$this->pricePreviewColumn] ?? null) ?? 0;
            $total += $cents * max(0, (int) $count);
        }

        return MoneyFormatter::format($total, null, MoneyFormatter::currency());
    }

    public function savePrices(): void
    {
        $this->authorizeAccess();
        $this->resetErrorBag();

        $cents = [];
        $invalid = [];

        foreach ($this->priceCells as $rowKey => $columns) {
            foreach ($columns as $column => $typed) {
                $value = MoneyInput::toCents($typed);

                // Text that is not an amount is the operator's typo, not a
                // missing price: say so on the cell rather than in the list.
                if ($value === null && $typed !== null && trim((string) $typed) !== '') {
                    $invalid["priceCells.{$rowKey}.{$column}"] = __('pricing.price_table.invalid');
                }

                $cents[$rowKey][$column] = $value;
            }
        }

        if ($invalid !== []) {
            throw ValidationException::withMessages($invalid);
        }

        try {
            app(SavePriceTable::class)(
                $this->priceProduct(),
                $cents,
                $this->priceSeasonIds,
                self::termsFromForm($this->priceTerms),
            );
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $terms = array_intersect_key($errors, array_flip(SavePriceTable::TERMS));

            throw ValidationException::withMessages(
                ['priceCells' => $errors['prices'] ?? []]
                + collect($terms)->mapWithKeys(static fn (array $messages, string $key): array => [
                    'priceTerms.' . ($key === 'deposit_fixed_cents' ? 'deposit_fixed' : $key) => $messages,
                ])->all(),
            );
        }

        $this->priceProduct()->refresh();
        $this->loadPriceTable();

        Notification::make()
            ->success()
            ->title(__('pricing.price_table.saved'))
            ->send();
    }

    /**
     * «⋯» on a period's column: its own deposit and deadlines, or the trip's.
     * Offered only on a saved period — one just ticked has no plan to hold them
     * until the table is saved.
     */
    public function periodTermsAction(): Action
    {
        return Action::make('periodTerms')
            ->label(__('pricing.periods.terms.open'))
            ->modalHeading(fn (array $arguments): string => __('pricing.periods.terms.heading', [
                'period' => (string) ($this->periodPlan($arguments)?->season->name ?? ''),
            ]))
            ->modalWidth('lg')
            ->fillForm(function (array $arguments): array {
                $plan = $this->periodPlan($arguments);

                if (! $plan instanceof RatePlan) {
                    return [];
                }

                $terms = SavePriceTable::termsOf($plan);

                return [
                    'own' => ! $plan->follows_trip_terms,
                    'deposit_type' => $terms['deposit_type'],
                    'deposit_percent' => $terms['deposit_percent'],
                    'deposit_fixed' => self::priceText($terms['deposit_fixed_cents']),
                    'balance_due_days_before_departure' => $terms['balance_due_days_before_departure'],
                    'min_lead_time_hours' => $terms['min_lead_time_hours'],
                    'max_advance_days' => $terms['max_advance_days'],
                ];
            })
            ->form([
                Toggle::make('own')
                    ->label(__('pricing.periods.terms.own'))
                    ->helperText(__('pricing.periods.terms.own_help'))
                    ->live(),
                ...array_map(
                    static fn ($field) => $field->visible(static fn (Get $get): bool => (bool) $get('own')),
                    self::termFields(),
                ),
            ])
            ->action(function (array $arguments, array $data): void {
                $plan = $this->periodPlan($arguments);

                if (! $plan instanceof RatePlan) {
                    return;
                }

                $terms = null;

                if ((bool) ($data['own'] ?? false)) {
                    // No field for the balance deadline here: left out, so the
                    // period keeps the one it has instead of losing it to a
                    // null (2026-09-25). SavePeriodTerms fills it back in.
                    $terms = self::termsFromForm($data);
                    unset($terms['balance_due_days_before_departure']);
                }

                app(SavePeriodTerms::class)($plan, $terms);

                Notification::make()->success()->title(__('pricing.periods.terms.saved'))->send();
            });
    }

    /**
     * The deposit and deadline fields, shared by the modal. The same five the
     * page shows under the table, in the same words.
     *
     * @return list<Field>
     */
    public static function termFields(): array
    {
        return [
            ToggleButtons::make('deposit_type')
                ->label(__('pricing.periods.terms.deposit'))
                ->options(DepositType::class)
                // Not grouped: three Greek labels side by side are wider than
                // a phone, and a grouped row cannot wrap. The whole row, so
                // the three stay on one line where there is room.
                ->inline()
                ->columnSpanFull()
                // A deposit on a price list the operator's switch never takes.
                ->helperText(static fn (Get $get): ?string => ($get('deposit_type') ?? DepositType::None->value) !== DepositType::None->value
                    && ! (Tenancy::current()->deposits_enabled ?? false)
                    ? __('pricing.periods.terms.deposits_off')
                    : null)
                ->live(),
            TextInput::make('deposit_percent')
                ->label(__('pricing.periods.terms.deposit_percent'))
                ->integer()
                ->minValue(1)
                ->maxValue(100)
                ->suffix('%')
                ->visible(static fn (Get $get): bool => $get('deposit_type') === DepositType::Percent->value),
            TextInput::make('deposit_fixed')
                ->label(__('pricing.periods.terms.deposit_fixed'))
                ->prefix('€')
                ->visible(static fn (Get $get): bool => $get('deposit_type') === DepositType::Fixed->value),
            TextInput::make('min_lead_time_hours')
                ->label(__('pricing.periods.terms.lead'))
                ->integer()
                ->minValue(0)
                ->suffix(__('pricing.periods.terms.hours')),
            TextInput::make('max_advance_days')
                ->label(__('pricing.periods.terms.advance'))
                ->helperText(__('pricing.periods.terms.advance_help'))
                ->integer()
                ->minValue(1)
                ->suffix(__('pricing.periods.terms.days')),
        ];
    }

    /**
     * What the page or the modal holds, as the plan's columns.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    public static function termsFromForm(array $form): array
    {
        $type = DepositType::tryFrom((string) ($form['deposit_type'] ?? '')) ?? DepositType::None;
        $int = static fn (mixed $value): ?int => $value === null || $value === '' ? null : (int) $value;

        return [
            'deposit_type' => $type->value,
            'deposit_percent' => $type === DepositType::Percent ? $int($form['deposit_percent'] ?? null) : null,
            'deposit_fixed_cents' => $type === DepositType::Fixed ? MoneyInput::toCents($form['deposit_fixed'] ?? null) : null,
            'balance_due_days_before_departure' => $int($form['balance_due_days_before_departure'] ?? null),
            'min_lead_time_hours' => $int($form['min_lead_time_hours'] ?? null) ?? 0,
            'max_advance_days' => $int($form['max_advance_days'] ?? null),
        ];
    }

    /** Typed prices stay; a column new on the page starts from what is saved, or empty. */
    private function fillPriceCells(PriceTable $table): void
    {
        $cells = [];

        foreach ($table->rows as $row) {
            foreach ($table->columns as $column) {
                $cells[$row['key']][$column['key']] = array_key_exists($column['key'], $this->priceCells[$row['key']] ?? [])
                    ? $this->priceCells[$row['key']][$column['key']]
                    : self::priceText($table->cents[$row['key']][$column['key']] ?? null);
            }

            $this->pricePax[$row['key']] ??= $row['is_base'] ? 2 : 0;
        }

        $this->priceCells = $cells;

        if (! in_array($this->pricePreviewColumn, array_column($table->columns, 'key'), true)) {
            $this->pricePreviewColumn = $table->columns[0]['key'] ?? '';
        }
    }

    /** @param  array<string, mixed>  $arguments */
    private function periodPlan(array $arguments): ?RatePlan
    {
        $plan = RatePlan::query()->with('season')->find($arguments['plan'] ?? null);

        return $plan instanceof RatePlan
            && (int) $plan->product_id === (int) $this->priceProduct()->getKey()
            && $plan->season_id !== null
            ? $plan
            : null;
    }

    private function priceProduct(): Product
    {
        /** @var Product */
        return $this->getRecord();
    }
}
