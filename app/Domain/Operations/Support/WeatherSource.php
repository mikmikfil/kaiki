<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

use App\Contracts\WeatherProvider;

/**
 * Who the forecast came from (ADR-0027).
 *
 * ## Why this is on the contract and not a config string
 *
 * The panel names its source, and the name has to stay true when the source
 * changes. A constant in a Blade template or a key in `config/kaiki.php` is a
 * second place to remember: swap the binding in
 * {@see WeatherProvider} for a paid provider and the
 * dashboard goes on crediting Open-Meteo, which is at best wrong and at worst a
 * licence claim about somebody else's data.
 *
 * Asking the provider means the credit cannot drift from the request.
 *
 * ## It is also a licence obligation, not decoration
 *
 * Open-Meteo publishes under CC BY 4.0. Attribution is a condition of use, so
 * the line under the panel is the thing that makes the feature licensed rather
 * than merely working — which is separate from, and does not solve, the
 * non-commercial limit on the free endpoint that ADR-0027 leaves open.
 */
final readonly class WeatherSource
{
    public function __construct(
        public string $name,
        public string $url,
    ) {}
}
