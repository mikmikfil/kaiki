<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\QuoteResource\Pages;

use App\Filament\App\Resources\QuoteResource;
use Filament\Resources\Pages\ListRecords;

/**
 * No create action, deliberately.
 *
 * A quote belongs to a booking — §4.4's guard is *"parent booking is
 * `quote_requested` or `quote_sent`"* — and a create button here would invite
 * an orphan. `BuildQuote` is reached from the booking, and from the **Revise**
 * action on this table.
 */
class ListQuotes extends ListRecords
{
    protected static string $resource = QuoteResource::class;
}
