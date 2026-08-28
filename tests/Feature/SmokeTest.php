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

it('declares sqlite as the local database stack', function (): void {
    // ENV-17: a clean checkout must land on SQLite. This asserts the *contract*
    // in .env.example rather than the runtime connection, because the CI MySQL
    // job legitimately runs the same suite against MySQL 8 (ADR-0015) — asserting
    // config('database.default') here would fail that job for the wrong reason.
    $example = file_get_contents(base_path('.env.example'));

    expect($example)->toContain('DB_CONNECTION=sqlite')
        ->and($example)->toContain('CACHE_STORE=database')
        ->and($example)->toContain('QUEUE_CONNECTION=database')
        ->and($example)->toContain('SESSION_DRIVER=database')
        ->and($example)->toContain('MAIL_MAILER=log')
        ->and($example)->toContain('APP_TIMEZONE=UTC');
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
