<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ProductResource\Pages;

use App\Domain\Pricing\Actions\SavePriceTable;
use App\Domain\Pricing\Support\PriceTable;
use App\Enums\BookingMode;
use App\Enums\PriceQuickFill;
use App\Filament\Forms\MoneyInput;
use App\Models\Product;
use App\Support\Format\MoneyFormatter;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

/**
 * The euro price table on the trip's edit page (product owner, 2026-09-17).
 *
 * ## Its own state and its own save button
 *
 * The cells are page properties rather than fields of the trip form. The form
 * saves the trip and its age bands; the table saves every rate plan's prices.
 * Mixing them would mean a new age band typed into the form had to be priced
 * in a table built before it existed. So the table saves on its own button,
 * says when it has unsaved changes, and is rebuilt after the trip is saved.
 *
 * ## The quick buttons are arithmetic on the server
 *
 * «Μισή τιμή» runs {@see PriceQuickFill::apply()} on each period's base price,
 * so the cents are the engine's cents, rounded the engine's way, and a test can
 * hold them. Alpine could do it faster and would be a second rounding rule.
 *
 * ## A changed adult price asks, never pushes
 *
 * The button last pressed on a row is remembered for this visit only. When the
 * base price then changes, the row offers «Ενημέρωση» instead of rewriting the
 * child's fare behind the operator's back. Typing into the row forgets it.
 */
trait ManagesPriceTable
{
    /** @var array<string, array<string, string|null>> row key => column key => euros as typed */
    public array $priceCells = [];

    /** @var array<string, string> row key => the quick-fill last pressed on it */
    public array $priceFills = [];

    /** @var array<string, bool> row key => the base changed since its quick-fill */
    public array $priceStale = [];

    /** @var array<string, int> row key => how many, for «Τι πληρώνει ο επισκέπτης» */
    public array $pricePax = [];

    public string $pricePreviewColumn = '';

    public bool $priceDirty = false;

    /** Build the cells from what is saved. Anything typed and unsaved is lost. */
    public function loadPriceTable(): void
    {
        $table = $this->priceTable();

        $this->priceCells = [];

        foreach ($table->rows as $row) {
            foreach ($table->columns as $column) {
                $cents = $table->cents[$row['key']][$column['key']] ?? null;
                $this->priceCells[$row['key']][$column['key']] = self::priceText($cents);
            }

            $this->pricePax[$row['key']] ??= $row['is_base'] ? 2 : 0;
        }

        $this->pricePreviewColumn = $table->columns[0]['key'] ?? '';
        $this->priceFills = [];
        $this->priceStale = [];
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

    /** How many cells of a saved, active period are still empty. */
    public function missingPriceCount(): int
    {
        $missing = 0;

        foreach ($this->priceTable()->columns as $column) {
            if ($column['plan_id'] === null || ! $column['active']) {
                continue;
            }

            foreach ($this->priceCells as $cells) {
                if (trim((string) ($cells[$column['key']] ?? '')) === '') {
                    $missing++;
                }
            }
        }

        return $missing;
    }

    public function priceTable(): PriceTable
    {
        return PriceTable::for($this->priceProduct());
    }

    /** Is there a table to show: a saved per-seat trip that has bands? */
    public function hasPriceTable(): bool
    {
        $product = $this->priceProduct();

        return $product->mode === BookingMode::PerSeat && $product->ageBands()->exists();
    }

    /** One quick-fill button: that row, every period, in euros. */
    public function fillPriceRow(string $rowKey, string $fill): void
    {
        $quickFill = PriceQuickFill::tryFrom($fill);
        $base = $this->priceTable()->baseRowKey();

        if ($quickFill === null || $base === null || $base === $rowKey || ! isset($this->priceCells[$rowKey])) {
            return;
        }

        foreach (array_keys($this->priceCells[$rowKey]) as $column) {
            $baseCents = MoneyInput::toCents($this->priceCells[$base][$column] ?? null);

            // A period with no adult price yet has nothing to take half of.
            if ($baseCents === null) {
                continue;
            }

            $this->priceCells[$rowKey][$column] = self::priceText($quickFill->apply($baseCents));
        }

        $this->priceFills[$rowKey] = $quickFill->value;
        unset($this->priceStale[$rowKey]);
        $this->priceDirty = true;
    }

    /** «Ενημέρωση»: press the row's last quick-fill again, from the new base. */
    public function refreshPriceRow(string $rowKey): void
    {
        $fill = $this->priceFills[$rowKey] ?? null;

        if ($fill !== null) {
            $this->fillPriceRow($rowKey, $fill);
        }
    }

    /** Livewire's hook for a typed cell: `priceCells.b12.p5`. */
    public function updatedPriceCells(mixed $value, string $key): void
    {
        $this->priceDirty = true;

        [$rowKey] = explode('.', $key) + [null];

        if ($rowKey === null) {
            return;
        }

        if ($rowKey === $this->priceTable()->baseRowKey()) {
            foreach (array_keys($this->priceFills) as $filled) {
                $this->priceStale[$filled] = true;
            }

            return;
        }

        // Typed by hand: the row is no longer «half of the adult».
        unset($this->priceFills[$rowKey], $this->priceStale[$rowKey]);
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
        $this->resetErrorBag('priceCells');

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
            app(SavePriceTable::class)($this->priceProduct(), $cents);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                'priceCells' => $exception->errors()['prices'] ?? array_merge(...array_values($exception->errors())),
            ]);
        }

        $this->priceProduct()->refresh();
        $this->loadPriceTable();

        Notification::make()
            ->success()
            ->title(__('pricing.price_table.saved'))
            ->send();
    }

    private function priceProduct(): Product
    {
        /** @var Product */
        return $this->getRecord();
    }
}
