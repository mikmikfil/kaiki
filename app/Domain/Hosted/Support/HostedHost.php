<?php

declare(strict_types=1);

namespace App\Domain\Hosted\Support;

/**
 * The two things `kaiki.tenancy.hosted_host` means, told apart.
 *
 * In production it is one string with one meaning: `book.kaiki.gr` is both the
 * hostname a request arrives on and the authority a URL is built from. Locally
 * they diverge, because the panel and the hosted pages run on two ports and the
 * value becomes `127.0.0.1:8001`.
 *
 * That divergence is not cosmetic. `Route::domain()` matches against
 * `$request->getHost()`, which **never** includes a port — so a domain
 * constraint carrying one matches nothing at all, and every hosted page 404s
 * while the URL that links to it looks perfectly correct. It cost a round trip
 * of "site can't be reached" to find, and this class exists so the next person
 * cannot make the same mistake by reading the config value directly.
 *
 * - {@see self::name()} — for matching, comparing and routing.
 * - {@see self::authority()} — for building a URL somebody will click.
 */
final class HostedHost
{
    /** The hostname, without a port. What a request is matched against. */
    public static function name(): string
    {
        return strtolower((string) strtok(self::authority(), ':'));
    }

    /** Host and port, as configured. What a link is built from. */
    public static function authority(): string
    {
        return strtolower((string) config('kaiki.tenancy.hosted_host'));
    }

    /** Is this the host the hosted pages are served on? */
    public static function matches(?string $host): bool
    {
        return $host !== null && strtolower($host) === self::name();
    }
}
