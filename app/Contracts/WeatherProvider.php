<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Domain\Operations\Support\WeatherSource;
use App\Domain\Operations\Support\WindForecast;

/**
 * A wind forecast for one place (ADR-0027).
 *
 * ## One method, for the same reason `PaymentGateway` has four
 *
 * The narrower this is, the cheaper the provider is to replace — and ADR-0027
 * says outright that Open-Meteo's free tier is non-commercial and will have to
 * be swapped for their paid endpoint, or for somebody else, before this reaches
 * a paying operator. A contract that leaked the provider's own shapes would
 * make that swap a rewrite rather than a class.
 *
 * So: coordinates and a day count in, a list of daily wind maxima out. No
 * "current conditions", no icons, no temperature. Rain does not cancel a boat
 * trip; wind does.
 *
 * ## Failure is null, not an exception and never a zero
 *
 * An implementation that cannot reach its provider returns null, and every
 * caller treats that as *we do not know*. A forecast of 0 Bft because a request
 * timed out is the one answer this feature must never give — it reads as "calm
 * on Thursday" on the screen an operator uses to decide whether to sail.
 */
interface WeatherProvider
{
    /**
     * Daily wind maxima for a location, starting today.
     *
     * @param  float  $latitude  decimal degrees
     * @param  float  $longitude  decimal degrees
     * @param  string  $timezone  the operator's, so "Thursday" means their Thursday
     * @param  int  $days  how many days ahead, today included
     * @return list<WindForecast>|null null when the forecast could not be obtained
     */
    public function dailyWind(float $latitude, float $longitude, string $timezone, int $days): ?array;

    /**
     * Who to credit for the numbers.
     *
     * On the contract rather than in a template or a config key, because the
     * panel names its source and the name has to stay true when the binding
     * changes — crediting Open-Meteo for somebody else's data is a licence
     * claim, not a stale string. For Open-Meteo it is also an obligation:
     * CC BY 4.0 makes attribution a condition of use.
     */
    public function source(): WeatherSource;
}
