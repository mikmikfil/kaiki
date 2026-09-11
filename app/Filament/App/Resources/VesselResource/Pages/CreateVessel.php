<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\VesselResource\Pages;

use App\Domain\Tenancy\Support\PlanLimits;
use App\Filament\App\Resources\VesselResource;
use App\Filament\App\Resources\VesselResource\Pages\Concerns\TranslatesVesselFormData;
use App\Models\Tenant;
use App\Support\Tenancy;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateVessel extends CreateRecord
{
    use TranslatesVesselFormData;

    protected static string $resource = VesselResource::class;

    /**
     * SAA-8, at the point of creation.
     *
     * After validation rather than at mount, so a person who reached this form
     * by its URL is told why on the button that would have created the boat —
     * and so a form error (a duplicate name) is still reported first. The list
     * page is the real gate: at the limit its "new" button becomes the upgrade.
     */
    protected function beforeCreate(): void
    {
        $tenant = Tenancy::current();

        if ($tenant === null || PlanLimits::canAddVessel($tenant)) {
            return;
        }

        self::notifyLimit($tenant);

        $this->halt();
    }

    public static function notifyLimit(Tenant $tenant): void
    {
        Notification::make()
            ->title(__('plans.vessels.reached_title'))
            ->body(PlanLimits::vesselLimitMessage($tenant))
            ->warning()
            ->persistent()
            ->actions([
                NotificationAction::make('upgrade')
                    ->label(__('plans.upgrade'))
                    ->url(PlanLimits::upgradeUrl(), shouldOpenInNewTab: true)
                    ->button(),
            ])
            ->send();
    }
}
