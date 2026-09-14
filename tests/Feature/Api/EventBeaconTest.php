<?php

declare(strict_types=1);

use App\Domain\Analytics\Support\AnalyticsMetric;
use App\Models\AnalyticsDaily;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

use function Pest\Laravel\postJson;

use Tests\Support\Api\CatalogRequest;

/*
|--------------------------------------------------------------------------
| ADR-0032: the widget's analytics beacon
|--------------------------------------------------------------------------
|
| This is a **public write**, reachable by anybody holding a publishable key —
| which is a key that sits in the source of a public web page. Two things follow
| and both are asserted here:
|
| - what protects the table is the allow-list, not the status code. Only the
|   eight event names WGT-11 fixed are counted, and the only dimension a browser
|   may set is a product uuid that looks like one;
| - it answers 204 whatever happens. It is called from a page in the middle of
|   selling something, and a 400 here is one defect in the widget's error
|   handling away from a guest seeing a failure.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-20 10:00:00');
});

it('counts a known event against the key own operator', function (): void {
    [$tenant, $key] = CatalogRequest::key();

    postJson(
        CatalogRequest::url('/events'),
        ['event' => 'kaiki:booking-started', 'product_uuid' => '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b'],
        ['Authorization' => "Bearer {$key}"],
    )->assertNoContent();

    Tenancy::forTenant($tenant, function (): void {
        $row = AnalyticsDaily::query()->sole();

        expect($row->metric)->toBe(AnalyticsMetric::BookingStarted)
            ->and($row->count)->toBe(1)
            ->and($row->dimension)->toBe('product')
            ->and($row->dimension_value)->toBe('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b');
    });
})->group('fast');

it('counts a batch, because a beacon cannot be retried', function (): void {
    [$tenant, $key] = CatalogRequest::key();

    postJson(
        CatalogRequest::url('/events'),
        ['events' => [
            ['event' => 'kaiki:ready'],
            ['event' => 'kaiki:ready'],
            ['event' => 'kaiki:product-viewed'],
        ]],
        ['Authorization' => "Bearer {$key}"],
    )->assertNoContent();

    Tenancy::forTenant($tenant, function (): void {
        expect(AnalyticsDaily::query()->count())->toBe(2)
            ->and(AnalyticsDaily::query()->where('metric', AnalyticsMetric::WidgetReady->value)->sole()->count)->toBe(2);
    });
})->group('fast');

it('ignores an event name nobody fixed, and still answers 204', function (): void {
    [$tenant, $key] = CatalogRequest::key();

    postJson(
        CatalogRequest::url('/events'),
        ['event' => 'kaiki:something-invented'],
        ['Authorization' => "Bearer {$key}"],
    )->assertNoContent();

    Tenancy::forTenant($tenant, function (): void {
        expect(AnalyticsDaily::query()->count())->toBe(0);
    });
})->group('fast');

it('refuses to put prose in the dimension column', function (): void {
    [$tenant, $key] = CatalogRequest::key();

    postJson(
        CatalogRequest::url('/events'),
        ['event' => 'kaiki:product-viewed', 'product_uuid' => str_repeat('a', 300)],
        ['Authorization' => "Bearer {$key}"],
    )->assertNoContent();

    Tenancy::forTenant($tenant, function (): void {
        // Counted, because the event is real; undimensioned, because what was
        // sent is not a uuid.
        $row = AnalyticsDaily::query()->sole();

        expect($row->count)->toBe(1)
            ->and($row->dimension)->toBe('')
            ->and($row->dimension_value)->toBe('');
    });
})->group('fast');

it('takes money only from the one metric that carries it', function (): void {
    [$tenant, $key] = CatalogRequest::key();

    postJson(
        CatalogRequest::url('/events'),
        ['events' => [
            ['event' => 'kaiki:booking-confirmed', 'value_cents' => 4200],
            // A price on a step that does not carry one is dropped: the widget
            // never computes a price and this endpoint never records one it was
            // not expecting.
            ['event' => 'kaiki:product-viewed', 'value_cents' => 999999],
        ]],
        ['Authorization' => "Bearer {$key}"],
    )->assertNoContent();

    Tenancy::forTenant($tenant, function (): void {
        expect(AnalyticsDaily::query()->where('metric', AnalyticsMetric::BookingConfirmed->value)->sole()->value_cents)->toBe(4200)
            ->and(AnalyticsDaily::query()->where('metric', AnalyticsMetric::ProductViewed->value)->sole()->value_cents)->toBe(0);
    });
})->group('fast');

it('answers 204 for a body that makes no sense at all', function (): void {
    [, $key] = CatalogRequest::key();

    postJson(CatalogRequest::url('/events'), ['nonsense' => true], ['Authorization' => "Bearer {$key}"])
        ->assertNoContent();

    postJson(CatalogRequest::url('/events'), [], ['Authorization' => "Bearer {$key}"])
        ->assertNoContent();
})->group('fast');

it('still wants a key, because a public write is not an open one', function (): void {
    postJson(CatalogRequest::url('/events'), ['event' => 'kaiki:ready'])->assertUnauthorized();
})->group('fast');
