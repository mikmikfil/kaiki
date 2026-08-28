<?php

declare(strict_types=1);

use function Pest\Laravel\get;

it('boots the application and serves the root route', function (): void {
    get('/')->assertOk();
});

it('stores time in UTC regardless of the operator timezone', function (): void {
    // Display timezone is per tenant (default Europe/Athens); storage is always
    // UTC. See docs/spec.md CNV-2 and ENV-14.
    expect(config('app.timezone'))->toBe('UTC');
});

it('runs on sqlite locally', function (): void {
    // MySQL 8 exists only in CI and production (ADR-0015). If this fails on a
    // developer machine, someone has pointed .env at MySQL and the SQLite/MySQL
    // parity contract is no longer being exercised.
    expect(config('database.default'))->toBe('sqlite');
});

it('has a directory for every bounded context', function (): void {
    $contexts = [
        'Catalog', 'Availability', 'Pricing', 'Booking', 'Payments',
        'Compliance', 'Notifications', 'Branding', 'Import',
    ];

    foreach ($contexts as $context) {
        expect(is_dir(app_path("Domain/{$context}/Actions")))
            ->toBeTrue("app/Domain/{$context}/Actions is missing");
    }

    expect(is_dir(app_path('Enums')))->toBeTrue();
});

it('ships no local Docker or make entry point', function (): void {
    // Local development is native PHP on Windows: no Docker, no make (spec
    // ENV-3, ENV-4). Compose and the Caddyfile are production-only artefacts
    // and live under docker/, never at the repository root.
    expect(file_exists(base_path('Makefile')))->toBeFalse();
    expect(file_exists(base_path('docker-compose.yml')))->toBeFalse();
});
