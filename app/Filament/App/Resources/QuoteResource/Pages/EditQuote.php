<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\QuoteResource\Pages;

use App\Domain\Booking\Actions\BuildQuote;
use App\Filament\App\Resources\QuoteResource;
use App\Models\Quote;
use Filament\Resources\Pages\EditRecord;

/**
 * Editing a draft, and recomputing its totals afterwards.
 *
 * The totals live on the quote as well as on its lines (§2.5), so the list view
 * and the "pending quotes" card can sort and sum without a join per row. That
 * makes them derived data with two writers, and {@see BuildQuote::recomputeTotals()}
 * is the single one — called here so an operator cannot save a quote whose
 * header disagrees with its own lines.
 */
class EditQuote extends EditRecord
{
    protected static string $resource = QuoteResource::class;

    protected function afterSave(): void
    {
        /** @var Quote $quote */
        $quote = $this->getRecord();

        app(BuildQuote::class)->recomputeTotals($quote);
    }
}
