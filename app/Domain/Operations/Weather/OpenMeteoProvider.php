<?php

declare(strict_types=1);

namespace App\Domain\Operations\Weather;

use App\Contracts\WeatherProvider;
use App\Domain\Operations\Support\Beaufort;
use App\Domain\Operations\Support\WeatherSource;
use App\Domain\Operations\Support\WindForecast;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Open-Meteo's daily wind maxima (ADR-0027).
 *
 * ## Cached per place, never per vessel
 *
 * A fleet in one marina shares a sky. Keying the cache on the *port's*
 * coordinates rather than on the vessel means ten boats in Zea are one request,
 * not ten — and the dashboard renders on every page load, so the difference is
 * between one call an hour and one call per widget per operator per minute.
 *
 * The key is rounded to three decimals, about a hundred metres. Two ports that
 * close together have the same weather, and a key on seven decimals would be a
 * cache that never hits because a coordinate was edited by a metre.
 *
 * ## A failure caches nothing
 *
 * Deliberately. Caching a null would mean one bad minute suppresses the
 * forecast for an hour, on a screen whose entire purpose is telling somebody
 * about Thursday. The cost of not caching it is a retry on the next render,
 * which is what should happen.
 *
 * ## Units are pinned in the query, not assumed in the parser
 *
 * `wind_speed_unit=ms` is in the URL. Open-Meteo's default is km/h, and a
 * parser that assumed m/s against the default would read 25 km/h as 25 m/s —
 * force 4 reported as force 10, which cancels a season.
 */
final class OpenMeteoProvider implements WeatherProvider
{
    public function __construct(private readonly HttpFactory $http) {}

    /**
     * @return list<WindForecast>|null
     */
    public function dailyWind(float $latitude, float $longitude, string $timezone, int $days): ?array
    {
        $key = sprintf(
            'weather:%s:%s:%s:%d',
            number_format($latitude, 3, '.', ''),
            number_format($longitude, 3, '.', ''),
            $timezone,
            $days,
        );

        /** @var list<WindForecast>|null $cached */
        $cached = Cache::get($key);

        if ($cached !== null) {
            return $cached;
        }

        $forecast = $this->fetch($latitude, $longitude, $timezone, $days);

        if ($forecast === null) {
            // See the class docblock: a failure is not cached.
            return null;
        }

        Cache::put($key, $forecast, now()->addMinutes(self::ttlMinutes()));

        return $forecast;
    }

    /**
     * @return list<WindForecast>|null
     */
    private function fetch(float $latitude, float $longitude, string $timezone, int $days): ?array
    {
        try {
            $response = $this->http
                ->timeout(self::timeoutSeconds())
                ->get(rtrim(self::baseUri(), '/') . '/v1/forecast', [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'daily' => 'wind_speed_10m_max,wind_gusts_10m_max',
                    // Pinned. See the class docblock — the default is km/h.
                    'wind_speed_unit' => 'ms',
                    // So that "Thursday" is the operator's Thursday and not UTC's.
                    'timezone' => $timezone,
                    'forecast_days' => $days,
                ]);
        } catch (Throwable $e) {
            Log::warning('weather.unreachable', [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('weather.refused', [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'status' => $response->status(),
            ]);

            return null;
        }

        return $this->parse($response->json());
    }

    /**
     * @return list<WindForecast>|null
     */
    private function parse(mixed $body): ?array
    {
        if (! is_array($body)) {
            return null;
        }

        $dates = data_get($body, 'daily.time');
        $means = data_get($body, 'daily.wind_speed_10m_max');
        $gusts = data_get($body, 'daily.wind_gusts_10m_max');

        if (! is_array($dates) || ! is_array($means)) {
            return null;
        }

        $out = [];

        foreach (array_values($dates) as $index => $date) {
            $mean = $means[$index] ?? null;

            // A day the provider could not fill is skipped rather than
            // defaulted. Zero would read as calm, which is the one wrong
            // answer this feature cannot give.
            if (! is_string($date) || ! is_numeric($mean)) {
                continue;
            }

            $gust = is_array($gusts) && is_numeric($gusts[$index] ?? null)
                ? Beaufort::fromMetresPerSecond((float) $gusts[$index])
                : null;

            $out[] = new WindForecast(
                date: $date,
                meanForce: Beaufort::fromMetresPerSecond((float) $mean),
                gustForce: $gust,
            );
        }

        return $out === [] ? null : $out;
    }

    public function source(): WeatherSource
    {
        // Not read from config. The credit belongs to whoever answered the
        // request, and this class is the only thing that knows that.
        return new WeatherSource('Open-Meteo', 'https://open-meteo.com/');
    }

    private static function baseUri(): string
    {
        $uri = config('kaiki.weather.base_uri');

        return is_string($uri) && $uri !== '' ? $uri : 'https://api.open-meteo.com';
    }

    private static function timeoutSeconds(): int
    {
        $seconds = config('kaiki.weather.timeout_seconds', 8);

        return is_int($seconds) && $seconds > 0 ? $seconds : 8;
    }

    private static function ttlMinutes(): int
    {
        $minutes = config('kaiki.weather.cache_minutes', 180);

        return is_int($minutes) && $minutes > 0 ? $minutes : 180;
    }
}
