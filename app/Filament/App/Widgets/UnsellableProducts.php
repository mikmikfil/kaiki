<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets;

use App\Enums\BookingMode;
use App\Enums\ProductStatus;
use App\Models\Product;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Published trips that cannot be priced (spec PRC-5).
 *
 * PRC-5 asks for *"a panel warning rather than a guest-facing error"*. This is
 * that warning, and its whole justification is the word **rather**: the guest
 * side of PRC-5 is silence — availability omits the product, no price is shown,
 * no error is raised. Silence is correct for a tourist and useless for the
 * operator, who would otherwise find out from a phone call asking why the trip
 * cannot be booked.
 *
 * ## `price_from_cents` is the signal, and it is already derived
 *
 * A product with no resolvable plan has a null `price_from_cents` (§1.9), which
 * is one indexed column read rather than a resolution pass per product per
 * date. `quote` products are excluded because a null there is the correct and
 * permanent state, not a warning — a charter agreed by phone has no price by
 * design.
 *
 * Drafts are excluded too: an unfinished product with no plan is the normal
 * middle of building one, and a dashboard that shouts about every draft is a
 * dashboard operators learn to ignore.
 */
class UnsellableProducts extends TableWidget
{
    protected static ?int $sort = 10;

    protected int|string|array $columnSpan = 'full';

    /**
     * Hidden entirely when there is nothing wrong.
     *
     * A permanently visible "0 problems" panel is furniture. This appears only
     * when it has something to say, which is what makes it worth reading when
     * it does.
     */
    public static function canView(): bool
    {
        return self::query()->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('pricing.unsellable.heading'))
            ->description(__('pricing.unsellable.description'))
            ->query(self::query())
            ->columns([
                TextColumn::make('title')
                    ->label(__('catalog.product.table.title')),

                TextColumn::make('status')
                    ->label(__('catalog.product.table.status'))
                    ->badge()
                    ->formatStateUsing(static fn (ProductStatus $state): string => $state->label()),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('pricing.unsellable.empty'));
    }

    /** @return Builder<Product> */
    protected static function query(): Builder
    {
        return Product::query()
            ->whereNull('price_from_cents')
            ->where('status', ProductStatus::Active)
            ->whereNot('mode', BookingMode::Quote);
    }
}
