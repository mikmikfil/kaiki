<?php

declare(strict_types=1);

use App\Providers\ApiRateLimitServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\AuditServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\AppPanelProvider;

return [
    ApiRateLimitServiceProvider::class,
    AppServiceProvider::class,
    AuditServiceProvider::class,
    AdminPanelProvider::class,
    AppPanelProvider::class,
];
