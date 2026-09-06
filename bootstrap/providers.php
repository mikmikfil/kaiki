<?php

declare(strict_types=1);

use App\Providers\ApiRateLimitServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\AuditServiceProvider;
use App\Providers\BookingServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\AppPanelProvider;
use App\Providers\IntegrationServiceProvider;

return [
    ApiRateLimitServiceProvider::class,
    AppServiceProvider::class,
    AuditServiceProvider::class,
    BookingServiceProvider::class,
    IntegrationServiceProvider::class,
    AdminPanelProvider::class,
    AppPanelProvider::class,
];
