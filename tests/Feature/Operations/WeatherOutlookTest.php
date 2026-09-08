<?php

declare(strict_types=1);

use App\Contracts\WeatherProvider;
use App\Domain\Operations\Support\WeatherOutlook;
use App\Domain\Operations\Support\WindForecast;
use App\Enums\DepartureStatus;
use App\Models\Departure;
use App\Models\Port;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| ADR-0027, OPS-6: the join, which is the feature
|--------------------------------------------------------------------------
|
| An operator already has the forecast on their phone. What they do not have is
| "Thursday and Friday are over Nefeli's limit — three departures, twenty-seven
| passengers", and a link to the screen that shows what each of those guests is
| owed. Everything worth testing here is about that join.
|
| Two silences are as important as the rows: a vessel with no limit set is
| absent because its operator never asked for this, and a provider that could
| not be reached is absent because "we do not know" and "it is calm" are
| different answers.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-08 06:00:00');
});

/**
 * A provider that answers with whatever the test hands it.
 *
 * @param  list<WindForecast>|null  $days
 */
function stubWeather(?array $days): void
{
    app()->instance(WeatherProvider::class, new class($days) implements WeatherProvider
    {
        /** @param list<WindForecast>|null $days */
        public function __construct(private readonly ?array $days) {}

        public function dailyWind(float $latitude, float $longitude, string $timezone, int $days): ?array
        {
            return $this->days;
        }
    });
}

/** @return array{0: Tenant, 1: Vessel} */
function weatherFixture(?int $limit = 6): array
{
    $tenant = Tenant::factory()->create(['timezone' => 'Europe/Athens']);

    $vessel = Tenancy::forTenant($tenant, function () use ($limit): Vessel {
        $port = Port::factory()->create([
            'name' => ['el' => 'Μαρίνα Ζέας', 'en' => 'Zea Marina'],
            'lat' => '37.9339000',
            'lng' => '23.6469000',
        ]);

        return Vessel::factory()->create([
            'name' => 'Θάλασσα',
            'home_port_id' => $port->getKey(),
            'max_wind_bft' => $limit,
        ]);
    });

    return [$tenant, $vessel];
}

/** @return list<array{vessel: Vessel, days: list<WindForecast>, rough: list<WindForecast>, departures: int, passengers: int}> */
function outlookFor(Tenant $tenant): array
{
    return Tenancy::forTenant(
        $tenant,
        fn (): array => (new WeatherOutlook(app(WeatherProvider::class), $tenant->timezone))->all(),
    );
}

it('reports the days over a boat\'s own limit and what is booked on them', function (): void {
    [$tenant, $vessel] = weatherFixture(limit: 6);

    stubWeather([
        new WindForecast('2026-09-08', 4, 5),
        new WindForecast('2026-09-09', 5, 6),
        new WindForecast('2026-09-10', 7, 8),
        new WindForecast('2026-09-11', 6, 9),
    ]);

    Tenancy::forTenant($tenant, function () use ($vessel): void {
        // On a rough day, with people on it.
        $rough = Departure::factory()->for($vessel)->at('2026-09-10', '10:00')->create();
        $rough->forceFill(['seats_sold' => 12])->save();

        // On a calm day — must not be counted.
        $calm = Departure::factory()->for($vessel)->at('2026-09-08', '10:00')->create();
        $calm->forceFill(['seats_sold' => 30])->save();
    });

    $rows = outlookFor($tenant);

    expect($rows)->toHaveCount(1)
        // Two days over 6: the 10th at 8, and the 11th where the gust decides.
        ->and($rows[0]['rough'])->toHaveCount(2)
        ->and($rows[0]['departures'])->toBe(1)
        ->and($rows[0]['passengers'])->toBe(12);
});

it('says nothing about a boat with no limit set', function (): void {
    [$tenant] = weatherFixture(limit: null);

    stubWeather([new WindForecast('2026-09-10', 11, 12)]);

    // A hurricane, and still silence. An operator who has not set a limit has
    // not asked for this, and guessing one would put a warning on their
    // dashboard about a boat they know better than we do.
    expect(outlookFor($tenant))->toBe([]);
});

it('says nothing when the forecast could not be fetched', function (): void {
    [$tenant] = weatherFixture(limit: 4);

    stubWeather(null);

    // Not a row of zeros. "0 Bft on Thursday" on the screen an operator uses to
    // decide whether to sail is the worst answer this feature could give.
    expect(outlookFor($tenant))->toBe([]);
});

it('says nothing on a week that is inside the limit', function (): void {
    [$tenant] = weatherFixture(limit: 8);

    stubWeather([
        new WindForecast('2026-09-08', 3, 4),
        new WindForecast('2026-09-09', 5, 6),
    ]);

    // "The weather is fine" is not news, and a panel that is always on screen
    // is one people stop reading before the week it matters.
    expect(outlookFor($tenant))->toBe([]);
});

it('counts a rough day with nothing booked as a row worth showing', function (): void {
    [$tenant] = weatherFixture(limit: 5);

    stubWeather([new WindForecast('2026-09-10', 8, 8)]);

    $rows = outlookFor($tenant);

    // The distinction the panel draws: rough and empty is a note, rough and
    // booked is a decision. Both belong on screen; only one needs acting on.
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['departures'])->toBe(0)
        ->and($rows[0]['passengers'])->toBe(0);
});

it('leaves a cancelled sailing out of the count', function (): void {
    [$tenant, $vessel] = weatherFixture(limit: 5);

    stubWeather([new WindForecast('2026-09-10', 8, 8)]);

    Tenancy::forTenant($tenant, function () use ($vessel): void {
        $cancelled = Departure::factory()->for($vessel)->at('2026-09-10', '10:00')->create();
        $cancelled->forceFill(['seats_sold' => 20, 'status' => DepartureStatus::Cancelled])->save();
    });

    // Already cancelled. Counting it would inflate the figure an operator reads
    // before deciding, and would keep showing work that is already done.
    $rows = outlookFor($tenant);

    expect($rows[0]['departures'])->toBe(0)
        ->and($rows[0]['passengers'])->toBe(0);
});

it('shows one operator nothing of another\'s', function (): void {
    [$mine] = weatherFixture(limit: 4);
    [$theirs] = weatherFixture(limit: 4);

    stubWeather([new WindForecast('2026-09-10', 9, 9)]);

    // Both have a rough day; each sees only their own boat.
    expect(outlookFor($mine))->toHaveCount(1)
        ->and(outlookFor($theirs))->toHaveCount(1)
        ->and(outlookFor($mine)[0]['vessel']->tenant_id)->toBe($mine->getKey());
});
