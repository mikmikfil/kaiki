<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\VesselResource\Pages;

use App\Domain\Tenancy\Support\PlanLimits;
use App\Filament\App\Resources\VesselResource;
use App\Support\Tenancy;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVessels extends ListRecords
{
    protected static string $resource = VesselResource::class;

    /**
     * "New vessel", or — at the plan's limit — the way to a bigger plan (SAA-8).
     *
     * The button changes rather than disappearing: an operator who looks for
     * "new" and finds nothing assumes the product is broken, while one who
     * finds "upgrade" in its place knows exactly why.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        $allowed = self::canAdd();

        return [
            CreateAction::make()->visible($allowed),

            Action::make('upgrade')
                ->label(__('plans.upgrade'))
                ->icon('heroicon-o-arrow-up-circle')
                ->url(PlanLimits::upgradeUrl(), shouldOpenInNewTab: true)
                ->visible(! $allowed),
        ];
    }

    /** How many of the plan's boats are used, or why no more can be added. */
    public function getSubheading(): ?string
    {
        $tenant = Tenancy::current();

        if ($tenant === null) {
            return null;
        }

        return PlanLimits::canAddVessel($tenant)
            ? PlanLimits::vesselUsage($tenant)
            : PlanLimits::vesselLimitMessage($tenant);
    }

    private static function canAdd(): bool
    {
        $tenant = Tenancy::current();

        return $tenant === null || PlanLimits::canAddVessel($tenant);
    }
}
