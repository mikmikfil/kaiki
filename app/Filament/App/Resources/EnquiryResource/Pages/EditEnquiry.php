<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\EnquiryResource\Pages;

use App\Filament\App\Resources\EnquiryResource;
use Filament\Resources\Pages\EditRecord;

/**
 * Handling an enquiry: a status and an assignee, and nothing else.
 *
 * No delete action, deliberately — `docs/data-model.md` §2.5 makes spam a
 * status kept for thirty days rather than a row removed on sight, so that an
 * operator who suspects the filters ate a real message has somewhere to look.
 */
class EditEnquiry extends EditRecord
{
    protected static string $resource = EnquiryResource::class;
}
