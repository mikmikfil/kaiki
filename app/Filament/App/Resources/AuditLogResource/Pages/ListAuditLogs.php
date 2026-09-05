<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\AuditLogResource\Pages;

use App\Filament\App\Resources\AuditLogResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The trail, newest first (ADR-0025 §4).
 *
 * The only page this resource has. There is no create page and no edit page
 * because there is nothing to create or edit — the ordering, the eager load and
 * the filters all live on the resource, so this class exists to name the route.
 */
class ListAuditLogs extends ListRecords
{
    protected static string $resource = AuditLogResource::class;

    public function getHeading(): string
    {
        return __('audit.heading');
    }

    public function getSubheading(): ?string
    {
        // Says the two things an operator needs to know before trusting it:
        // how long it is kept, and that nobody can edit it.
        return __('audit.subheading');
    }

    /**
     * Deliberately no header actions.
     *
     * Not even an export: ADR-0025 puts a UI for exporting the trail out of
     * scope, and #53's own issue says export is M5 with the other CSV exports.
     *
     * @return array<int, never>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
