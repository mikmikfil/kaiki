<?php

declare(strict_types=1);

use App\Logging\TenantContextProcessor;
use App\Models\Tenant;
use App\Support\Tenancy;
use Monolog\Level;
use Monolog\LogRecord;

/*
 * Spec OBS-2: every log line carries `tenant_id` and `request_id`.
 *
 * In a single-database multi-tenant system a log line without a tenant is close
 * to useless — "booking confirmation failed" is unanswerable until you know
 * whose. `request_id` is what ties a queued job's failure back to the click
 * that caused it.
 */

function record(string $message = 'test'): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'testing',
        level: Level::Info,
        message: $message,
    );
}

it('stamps the resolved tenant on a log record', function (): void {
    $tenant = Tenant::factory()->create();

    $processed = Tenancy::forTenant($tenant, fn (): LogRecord => (new TenantContextProcessor)(record()));

    expect($processed->extra['tenant_id'])->toBe($tenant->getKey());
})->group('fast');

it('records a null tenant rather than omitting the key', function (): void {
    // An absent key and a null value read the same in a log aggregator only if
    // you already know which. Always emitting the field means "no tenant" is a
    // fact in the record rather than something inferred from silence.
    $processed = (new TenantContextProcessor)(record());

    expect($processed->extra)->toHaveKey('tenant_id')
        ->and($processed->extra['tenant_id'])->toBeNull();
})->group('fast');

it('stamps a request id that is stable across lines', function (): void {
    $first = (new TenantContextProcessor)(record('first'));
    $second = (new TenantContextProcessor)(record('second'));

    expect($first->extra['request_id'])->toBeString()
        ->and($first->extra['request_id'])->not->toBeEmpty()
        // Both lines from one request must share an id, or the field cannot be
        // used to group them — which is its only purpose.
        ->and($second->extra['request_id'])->toBe($first->extra['request_id']);
})->group('fast');

it('preserves any extra data already on the record', function (): void {
    $processed = (new TenantContextProcessor)(record()->with(extra: ['booking_reference' => 'KAI-7F3K2']));

    expect($processed->extra['booking_reference'])->toBe('KAI-7F3K2')
        ->and($processed->extra)->toHaveKey('tenant_id')
        ->and($processed->extra)->toHaveKey('request_id');
})->group('fast');
