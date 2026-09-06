<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\EnquiryResource\Pages;

use App\Filament\App\Resources\EnquiryResource;
use Filament\Resources\Pages\ListRecords;

/**
 * No create action.
 *
 * An enquiry is something a stranger sends, not something an operator writes.
 * A create button here would produce a row that looks like a guest's message
 * and is not one — in a table an operator reads as a record of what people
 * actually asked.
 */
class ListEnquiries extends ListRecords
{
    protected static string $resource = EnquiryResource::class;
}
