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
     * One tab per status, drafts counted on theirs (product owner, 2026-09-17),
     * so an unfinished trip cannot sit unnoticed in a long list.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make(__('catalog.product.table.tabs.all'))];

        foreach (ProductStatus::cases() as $status) {
            $tab = Tab::make($status->label())
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('status', $status->value));

            if ($status === ProductStatus::Draft) {
                $drafts = Product::query()->where('status', ProductStatus::Draft->value)->count();

                $tab->badge($drafts > 0 ? $drafts : null)->badgeColor('warning');
            }

            $tabs[$status->value] = $tab;
        }

        return $tabs;
    }
}
