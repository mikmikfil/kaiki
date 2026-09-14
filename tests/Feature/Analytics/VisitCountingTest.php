<?php

declare(strict_types=1);

use App\Domain\Analytics\Actions\CountAnalyticsEvent;
use App\Domain\Analytics\Support\AnalyticsFigures;
use App\Domain\Analytics\Support\AnalyticsMetric;
use App\Domain\Analytics\Support\LocalRange;
use App\Models\AnalyticsDaily;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\get;

use Tests\Support\Hosted\HostedRequest;
use Tests\Support\Hosted\OperatorPage;

/*
|--------------------------------------------------------------------------
| ADR-0032: visits counted first-party, cookieless, as aggregates
|--------------------------------------------------------------------------
|
| The decision is what these tests are really about, so they assert its terms
| rather than an implementation:
|
| - a row is a **count**, never a person: no visitor id, no session, no IP, no
|   user agent, and one row per tenant per day per metric however many visitors
|   there were;
| - a hosted page is counted **on the server**, so a blocker cannot remove it
|   and nothing is written to the visitor's browser (GDR-12);
| - counting twice adds to one row rather than making a second, which is the
|   only thing keeping this table bounded;
| - and nothing about it can break a page.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-20 10:00:00');
});

it('adds to one row rather than writing a second', function (): void {
    $tenant = Tenant::factory()->create(['timezone' => 'Europe/Athens']);
    $count = app(CountAnalyticsEvent::class);

    $count($tenant, AnalyticsMetric::PageView);
    $count($tenant, AnalyticsMetric::PageView);
    $count($tenant, AnalyticsMetric::PageView);

    Tenancy::forTenant($tenant, function (): void {
        $row = AnalyticsDaily::query()->sole();

        expect($row->count)->toBe(3)
            ->and($row->metric)->toBe(AnalyticsMetric::PageView)
            ->and($row->date->toDateString())->toBe('2026-08-20');
    });
})->group('fast');

it('keeps a day in the operator own calendar', function (): void {
    // 00:30 in Athens on the 21st is 21:30 UTC on the 20th. The count belongs
    // to the 21st, because every other figure on the statistics page is
    // bucketed by the operator's calendar and a count that used the server's
    // would sit one row away from the revenue it explains.
    $tenant = Tenant::factory()->create(['timezone' => 'Europe/Athens']);

    Carbon::setTestNow('2026-08-20 21:30:00');

    app(CountAnalyticsEvent::class)($tenant, AnalyticsMetric::PageView);

    Tenancy::forTenant($tenant, function (): void {
        expect(AnalyticsDaily::query()->sole()->date->toDateString())->toBe('2026-08-21');
    });
})->group('fast');

it('sums money only where the metric carries it', function (): void {
    $tenant = Tenant::factory()->create(['timezone' => 'Europe/Athens']);
    $count = app(CountAnalyticsEvent::class);

    $count($tenant, AnalyticsMetric::BookingConfirmed, '', '', 4200);
    $count($tenant, AnalyticsMetric::BookingConfirmed, '', '', 1800);

    Tenancy::forTenant($tenant, function (): void {
        $row = AnalyticsDaily::query()->sole();

        expect($row->count)->toBe(2)->and($row->value_cents)->toBe(6000);
    });
})->group('fast');

it('counts a hosted page on the server, with nothing written to the browser', function (): void {
    $tenant = OperatorPage::operator('counted');

    get(HostedRequest::url('/counted'))->assertOk();

    // ADR-0032's promise, asserted where it actually lives: the table has no
    // column that could identify anybody. A test against the response would be
    // asserting about Laravel's session cookie, which is not what GDR-12 is
    // about — what matters is that nothing *about the visitor* is stored.
    foreach (['ip', 'ip_address', 'user_agent', 'visitor', 'visitor_id', 'session', 'session_id'] as $column) {
        expect(Schema::hasColumn('analytics_daily', $column))->toBeFalse("analytics_daily has a {$column} column.");
    }

    Tenancy::forTenant($tenant, function (): void {
        expect(AnalyticsDaily::query()->where('metric', AnalyticsMetric::PageView->value)->sole()->count)->toBe(1);
    });
})->group('fast');

it('does not count an obvious robot', function (): void {
    $tenant = OperatorPage::operator('crawled');

    get(HostedRequest::url('/crawled'), ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)'])->assertOk();

    Tenancy::forTenant($tenant, function (): void {
        expect(AnalyticsDaily::query()->count())->toBe(0);
    });
})->group('fast');

it('reads the funnel as counts and the ratio between two of them', function (): void {
    $tenant = Tenant::factory()->create(['timezone' => 'Europe/Athens']);
    $count = app(CountAnalyticsEvent::class);
    $figures = new AnalyticsFigures('Europe/Athens');

    foreach (range(1, 100) as $ignored) {
        $count($tenant, AnalyticsMetric::PageView);
    }

    foreach (range(1, 25) as $ignored) {
        $count($tenant, AnalyticsMetric::ProductViewed);
    }

    foreach (range(1, 5) as $ignored) {
        $count($tenant, AnalyticsMetric::BookingConfirmed);
    }

    $funnel = Tenancy::forTenant($tenant, fn (): array => $figures->funnel(LocalRange::month('Europe/Athens')));

    expect($funnel[0])->toBe(['metric' => 'page_view', 'count' => 100, 'value' => 0, 'ratio' => null])
        // A quarter of the page views reached a trip. Not "a quarter of
        // visitors", which is a sentence this data cannot support.
        ->and($funnel[1]['count'])->toBe(25)
        ->and($funnel[1]['ratio'])->toBe(0.25)
        // Nobody got as far as availability: a real zero, measured against the
        // twenty-five above it.
        ->and($funnel[2]['count'])->toBe(0)
        ->and($funnel[2]['ratio'])->toBe(0.0)
        // And the step below *that* has no ratio at all rather than a zero,
        // because its predecessor was never counted — 0/0 is not "nought per
        // cent", it is a question nothing answered.
        ->and($funnel[3]['ratio'])->toBeNull();
})->group('fast');

it('has no funnel to show before anything was counted', function (): void {
    $tenant = Tenant::factory()->create(['timezone' => 'Europe/Athens']);
    $figures = new AnalyticsFigures('Europe/Athens');

    expect(Tenancy::forTenant($tenant, fn (): bool => $figures->hasCounts(LocalRange::month('Europe/Athens'))))
        ->toBeFalse();
})->group('fast');

it('never lets one tenant see another one counts', function (): void {
    $mine = Tenant::factory()->create(['timezone' => 'Europe/Athens']);
    $theirs = Tenant::factory()->create(['timezone' => 'Europe/Athens']);
    $count = app(CountAnalyticsEvent::class);

    $count($mine, AnalyticsMetric::PageView);
    $count($theirs, AnalyticsMetric::PageView);
    $count($theirs, AnalyticsMetric::PageView);

    $figures = new AnalyticsFigures('Europe/Athens');

    expect(Tenancy::forTenant($mine, fn (): int => $figures->visits(LocalRange::month('Europe/Athens'))))->toBe(1)
        ->and(Tenancy::forTenant($theirs, fn (): int => $figures->visits(LocalRange::month('Europe/Athens'))))->toBe(2);
})->group('fast');
