<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TenantResource\Pages;

use App\Filament\Admin\Resources\TenantResource;
use App\Policies\TenantPolicy;
use Filament\Resources\Pages\ListRecords;

/**
 * The merchant list.
 *
 * No header actions: there is nothing to create from here. Onboarding creates
 * operators (SAA-10, M7), and every other write is refused by
 * {@see TenantPolicy} until ADR-0025 settles the audit trail.
 */
class ListTenants extends ListRecords
{
    protected static string $resource = TenantResource::class;

    /** @return array<int, never> */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
