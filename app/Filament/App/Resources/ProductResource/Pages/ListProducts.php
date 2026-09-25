<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ProductResource\Pages;

use App\Enums\ProductStatus;
use App\Filament\App\Resources\ProductResource;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    /**
     * One tab per status, every one of them counted (product owner, 2026-09-17;
     * counts on all of them 2026-09-21).
     *
     * Only «Πρόχειρες» carried a number before, and it carried it as a warning —
     * an unfinished trip must not sit unnoticed in a long list. The rest carried
     * none, so the strip answered *«έχω κάτι μισοτελειωμένο;»* and not the
     * question an operator opens the catalogue with: *«πόσες εκδρομές πουλάω;»*.
     *
     * A zero is shown rather than hidden. On a strip where every other tab
     * carries a figure, a missing one reads as "not loaded" rather than as
     * "none" — and «Αρχειοθετημένες 0» is a useful, calm fact.
     *
     * The warning colour stays the drafts', and only while there are any: a
     * strip where everything is amber points at nothing.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $counts = $this->countsByStatus();

        $tabs = [
            'all' => Tab::make(__('catalog.product.table.tabs.all'))
                ->badge(array_sum($counts))
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->whereNull('deleted_at')),
        ];

        foreach (ProductStatus::cases() as $status) {
            $count = $counts[$status->value] ?? 0;

            $tab = Tab::make($status->label())
                ->badge($count)
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->whereNull('deleted_at')->where('status', $status->value));

            if ($status === ProductStatus::Draft && $count > 0) {
                $tab->badgeColor('warning');
            }

            $tabs[$status->value] = $tab;
        }

        /*
         * «Διαγραμμένες» (Mike, 25/9): the bin was built behind Filament's
         * filter button, where nobody looks. The resource query keeps deleted
         * rows (restore and edit need them), so every tab above says
         * `deleted_at is null` and this one says the opposite.
         */
        $tabs['trashed'] = Tab::make(__('catalog.product.table.tabs.trashed'))
            ->badge(Product::onlyTrashed()->count())
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->whereNotNull('deleted_at'));

        return $tabs;
    }

    /**
     * Every status counted in one query rather than one query per tab.
     *
     * Five round trips to draw a row of numbers is the kind of thing that never
     * shows on a seeded database and does on a real one. `toBase()` keeps the
     * tenant scope — it is a `where` on the builder, already applied — while
     * skipping the hydration of models nobody reads.
     *
     * @return array<string, int>
     */
    private function countsByStatus(): array
    {
        /** @var array<string, int> */
        return Product::query()
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();
    }
}
