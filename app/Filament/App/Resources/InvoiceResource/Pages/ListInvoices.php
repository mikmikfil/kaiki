<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\InvoiceResource\Pages;

use App\Enums\InvoiceStatus;
use App\Filament\App\Resources\InvoiceResource;
use App\Models\Invoice;
use Filament\Resources\Pages\ListRecords;

/**
 * The list. The warning above the table lives on the resource, not here.
 *
 * `getHeader()` was the obvious place for it and is the wrong one: Filament's
 * page header is the **whole** header — the title and the actions — so returning
 * a banner from it replaces «Παραστατικά» rather than sitting under it. Caught
 * by looking at the screen, which is the only way that particular mistake is
 * ever caught.
 *
 * It is a table header instead ({@see InvoiceResource::table()}), which renders
 * between the page title and the rows.
 */
class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    /**
     * Is anything on this operator's account a test document?
     *
     * MYD-11 requires an operator to be able to see they are in test mode. A
     * badge on every row says it and is ignored by the second week; a line that
     * appears **only** when a test document exists is read every time, because
     * its presence is itself the information.
     *
     * A query rather than a config read, deliberately. `config` says what the
     * *next* document will do; the rows say what the ones already issued did —
     * and an operator whose credentials changed last month needs to know that
     * September's went to a sandbox, whatever October's will do.
     *
     * Scoped to this tenant, so one operator's sandbox never warns another.
     * Cheap: `invoices_tenant_status_idx` covers it and the query stops at the
     * first row.
     */
    public static function hasTestDocuments(): bool
    {
        return Invoice::query()
            ->where('environment', '!=', 'live')
            // A pending document has not gone anywhere yet, and warning about
            // one that is about to be sent correctly would be noise.
            ->whereIn('status', [InvoiceStatus::Sent, InvoiceStatus::Failed])
            ->exists();
    }
}
