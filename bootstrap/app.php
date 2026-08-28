<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\EnsureTenantIsWritable;
use App\Http\Middleware\RequireApiKeyCapability;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            // Turns a presented API key into a resolved tenant (#6).
            'api.key' => AuthenticateApiKey::class,
            // Requires a scope on that key before any controller runs, so a
            // publishable key is refused a privileged write without the
            // application ever loading the record (spec SEC-5).
            'api.scope' => RequireApiKeyCapability::class,
            // Resolves exactly one tenant by the fixed TEN-4 order, or 404s.
            'tenant' => ResolveTenant::class,
            // Refuses unsafe methods for a tenant in read-only mode (TEN-9).
            'tenant.writable' => EnsureTenantIsWritable::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
