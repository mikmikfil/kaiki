<?php

declare(strict_types=1);

namespace App\Domain\Hosted\Support;

use App\Http\Requests\Api\V1\BookingCreateRequest;
use App\Http\Requests\Api\V1\CheckoutRequest;
use App\Models\ApiKey;

/**
 * May a browser be sent to this URL on behalf of this key? (SEC-7)
 *
 * ## One answer for two fields
 *
 * {@see CheckoutRequest} asks it of `return_url` (issue 111) and
 * {@see BookingCreateRequest} of `origin_url` (the «back to the website» link,
 * 2026-09-11). Both are an address a third party's page hands us and a guest's
 * browser is later sent to, from a page that looks like the operator's — which
 * is the shape of an open redirect. Two copies of the check would be two
 * answers to "may we send a guest there", and the second copy is the one that
 * forgets a port.
 *
 * ## The key's CORS list, not a second list
 *
 * An empty allow-list means any origin, exactly as it does for CORS — the panel
 * warns an operator about that. A page Kaiki serves itself (the hosted host and
 * `APP_URL`'s) is always allowed.
 *
 * ## `http` and `https` only
 *
 * Added when the second caller arrived, because `origin_url` is rendered as an
 * `href`. `javascript://anything/%0Aalert(1)` parses with a host, and under an
 * empty allow-list it would have passed an origin comparison and become a link
 * that runs script on a token page. No gateway and no guest needs any other
 * scheme, so `return_url` is held to the same rule.
 */
final class AllowedOrigin
{
    public static function permits(string $url, ?ApiKey $key): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $scheme = strtolower((string) $parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower((string) $parts['host']);

        // A page we serve ourselves — the hosted pages, and the guest booking
        // page a confirmation links to.
        if ($host === HostedHost::name() || $host === strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST))) {
            return true;
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $key instanceof ApiKey && $key->allowsOrigin($scheme . '://' . $host . $port);
    }
}
