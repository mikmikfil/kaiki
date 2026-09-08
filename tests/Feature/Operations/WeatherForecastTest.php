<?php

declare(strict_types=1);

use App\Contracts\WeatherProvider;
use App\Domain\Operations\Support\Beaufort;
use App\Domain\Operations\Support\WindForecast;
use App\Domain\Operations\Weather\OpenMeteoProvider;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| ADR-0027: the forecast, and the two ways it could lie
|--------------------------------------------------------------------------
|
| **The unit.** Open-Meteo defaults to km/h. A parser that assumed m/s against
| that default reads 25 km/h as 25 m/s — force 4 reported as force 10, which
| cancels a season. The unit is pinned in the query and asserted here.
|
| **The zero.** A provider that cannot be reached must yield *nothing*, never a
| calm forecast. "0 Bft on Thursday" on the screen an operator uses to decide
| whether to sail is the single worst answer this feature could give.
|
*/

beforeEach(function (): void {
    config(['kaiki.weather.base_uri' => 'https://weather.test']);

    // One fake, reading a holder the tests reassign.
    //
    // `Http::fake()` **appends** a stub rather than replacing the previous
    // one, and the first matching stub wins — so a second call in the same
    // test is silently ignored and every assertion after it runs against the
    // first response. The iCal suite has the same guard for the same reason.
    meteoHolder()->response = Http::response('', 503);

    Http::fake(static fn () => meteoHolder()->response);
});

/** The one mutable thing the fake reads. */
function meteoHolder(): object
{
    static $holder = null;

    $holder ??= new class
    {
        public mixed $response = null;
    };

    return $holder;
}

/** @param array<string, mixed> $daily */
function meteoResponse(array $daily): void
{
    meteoHolder()->response = Http::response(['daily' => $daily]);
}

function meteoFails(int $status = 503): void
{
    meteoHolder()->response = Http::response('', $status);
}

it('converts wind speed to the scale a skipper reads', function (): void {
    // The boundaries are the ones that matter: an operator who stops at 6 Bft
    // cares enormously about 10.7 against 10.8, and nowhere else on the scale.
    expect(Beaufort::fromMetresPerSecond(0.4))->toBe(0)
        ->and(Beaufort::fromMetresPerSecond(10.7))->toBe(5)
        ->and(Beaufort::fromMetresPerSecond(10.8))->toBe(6)
        ->and(Beaufort::fromMetresPerSecond(13.8))->toBe(6)
        ->and(Beaufort::fromMetresPerSecond(13.9))->toBe(7)
        // No upper bound on 12: a table that capped it would report a hurricane
        // as force 11.
        ->and(Beaufort::fromMetresPerSecond(45.0))->toBe(12)
        // Not a physical reading — a provider returning something unexpected.
        // Force 0 is the answer that cannot cause a cancellation on its own.
        ->and(Beaufort::fromMetresPerSecond(-3.0))->toBe(0);
});

it('reads the response in metres per second, because that is what it asked for', function (): void {
    meteoResponse([
        'time' => ['2026-09-08'],
        // 12 m/s is force 6. Read as km/h it would be force 3, and the day
        // would not be flagged at all.
        'wind_speed_10m_max' => [12.0],
        'wind_gusts_10m_max' => [12.0],
    ]);

    $forecast = app(OpenMeteoProvider::class)->dailyWind(37.9, 23.6, 'Europe/Athens', 4);

    expect($forecast)->toHaveCount(1)
        ->and($forecast[0]->meanForce)->toBe(6);

    // And the unit is actually pinned in the request rather than hoped for.
    Http::assertSent(fn ($request): bool => $request['wind_speed_unit'] === 'ms');
});

it('answers null when the provider cannot be reached, never a calm day', function (): void {
    meteoFails();

    $forecast = app(OpenMeteoProvider::class)->dailyWind(37.9, 23.6, 'Europe/Athens', 4);

    // Null, not an empty list and not a list of zeros. Every caller treats this
    // as "we do not know" and shows nothing.
    expect($forecast)->toBeNull();
});

it('does not cache a failure', function (): void {
    meteoFails();
    expect(app(OpenMeteoProvider::class)->dailyWind(37.9, 23.6, 'Europe/Athens', 4))->toBeNull();

    meteoResponse([
        'time' => ['2026-09-08'],
        'wind_speed_10m_max' => [3.0],
        'wind_gusts_10m_max' => [4.0],
    ]);

    // Caching the null would mean one bad minute suppresses the forecast for
    // three hours, on a panel whose whole purpose is telling somebody about
    // Thursday.
    expect(app(OpenMeteoProvider::class)->dailyWind(37.9, 23.6, 'Europe/Athens', 4))->not->toBeNull();
});

it('caches a success, so a dashboard render is not an outbound call', function (): void {
    meteoResponse([
        'time' => ['2026-09-08'],
        'wind_speed_10m_max' => [3.0],
        'wind_gusts_10m_max' => [4.0],
    ]);

    $provider = app(OpenMeteoProvider::class);

    $provider->dailyWind(37.9, 23.6, 'Europe/Athens', 4);
    $provider->dailyWind(37.9, 23.6, 'Europe/Athens', 4);

    // Ten boats in one marina share a sky, and the panel renders on every page
    // load. One request.
    Http::assertSentCount(1);
});

it('skips a day the provider could not fill rather than calling it calm', function (): void {
    meteoResponse([
        'time' => ['2026-09-08', '2026-09-09'],
        'wind_speed_10m_max' => [12.0, null],
        'wind_gusts_10m_max' => [15.0, null],
    ]);

    $forecast = app(OpenMeteoProvider::class)->dailyWind(37.9, 23.6, 'Europe/Athens', 4);

    // A missing value defaulted to zero would read as calm, which is the one
    // wrong answer available.
    expect($forecast)->toHaveCount(1)
        ->and($forecast[0]->date)->toBe('2026-09-08');
});

it('decides on the gust when the gust is higher', function (): void {
    $day = new WindForecast(date: '2026-09-08', meanForce: 5, gustForce: 8);

    // A mean of 5 with gusts to 8 is not a 5 Bft day to anybody on a flybridge.
    // Erring toward the larger figure is the safe direction: a day flagged that
    // turns out fine costs a second look at a screen; a day not flagged that
    // turns out rough costs a boat full of people.
    expect($day->force())->toBe(8)
        ->and($day->drivenByGust())->toBeTrue()
        ->and($day->exceeds(6))->toBeTrue()
        ->and($day->exceeds(8))->toBeFalse()
        // No limit set is not a limit of zero.
        ->and($day->exceeds(null))->toBeFalse();
});

it('is bound to the interface, so the provider is one line to replace', function (): void {
    // ADR-0027: Open-Meteo's free tier is non-commercial and has to be swapped
    // before this reaches a paying operator. That swap is the binding.
    expect(app(WeatherProvider::class))->toBeInstanceOf(OpenMeteoProvider::class);
});
