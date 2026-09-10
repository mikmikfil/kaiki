<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TenantResource\Pages;

use App\Filament\Admin\Resources\TenantResource;
use App\Policies\TenantPolicy;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

/**
 * The merchant list, and the one button that adds to it.
 *
 * There was no button for two milestones, and the comment here said onboarding
 * would provide one — while onboarding did not exist. See {@see TenantPolicy}:
 * the platform owner could look at their operators and had no way to acquire
 * another.
 *
 * Deleting is still not offered, and is refused by the policy rather than
 * merely absent.
 */
class ListTenants extends ListRecords
{
    protected static string $resource = TenantResource::class;

    /** @return array<int, Actions\CreateAction> */
    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label(__('tenants.create.action')),
        ];
    }
}
