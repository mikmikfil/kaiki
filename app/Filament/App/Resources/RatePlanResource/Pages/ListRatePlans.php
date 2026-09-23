<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\RatePlanResource\Pages;

use App\Filament\App\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\App\Resources\ProductResource\RelationManagers\RatePlansRelationManager;
use App\Filament\App\Resources\RatePlanResource;
use App\Filament\App\Widgets\UnsellableProducts;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Widgets\Widget;

class ListRatePlans extends ListRecords
{
    protected static string $resource = RatePlanResource::class;

    /**
     * **No «Προσθήκη Τιμοκαταλόγου»** (Mike, 2026-09-23).
     *
     * A price cannot exist before the trip it prices, and both ways of making
     * one already run through the trip: the first plan is written by
     * {@see CreateProduct},
     * and further periods are added from the trip's own Τιμές tab
     * ({@see RatePlansRelationManager}).
     *
     * A standalone create button was a third door into the same room whose
     * first question was «ποια εκδρομή;» — an operator who can answer that is
     * already on the trip. This screen is now what its heading says it is: a
     * summary of every price in the account, and a way in to edit one.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * What is missing, above what exists (product owner, 2026-09-21).
     *
     * The table below groups plans by trip, so a trip with **no** plan at all
     * cannot appear in it — and that is precisely the trip an operator opens
     * this screen to find. `UnsellableProducts` already answers it for
     * published trips, hides itself entirely when there is nothing wrong, and
     * explains in its own docblock why drafts are left out of it. Reusing it
     * keeps one query and one rule; a second "trips with no price" list here
     * would be a second rule, and the two would disagree the first time either
     * one changed.
     *
     * @return array<int, class-string<Widget>>
     */
    protected function getHeaderWidgets(): array
    {
        return [UnsellableProducts::class];
    }
}
