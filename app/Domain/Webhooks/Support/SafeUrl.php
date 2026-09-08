<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Support;

/**
 * A webhook URL a guest-facing platform is willing to call (`docs/api.md` §8.4).
 *
 * ## The attack this exists to refuse
 *
 * An operator types a URL and Kaiki fetches it, from inside the platform's own
 * network, with the platform's own credentials to everything on that network.
 * `http://169.254.169.254/latest/meta-data/iam/` on AWS returns the instance's
 * role credentials. `http://127.0.0.1:6379` is Redis. `http://10.0.0.5/` is
 * whatever else is in the VPC. None of it is reachable from the internet, and
 * all of it is reachable from the thing that sends webhooks.
 *
 * That is server-side request forgery, and a webhook feature is the friendliest
 * possible way to hand somebody one — the product asks for a URL and promises
 * to call it.
 *
 * ## HTTPS only, and no redirects
 *
 * Plain HTTP would put a booking's guest name and email on the wire in clear,
 * and there is no operator whose integration platform lacks TLS in 2026.
 * Redirects are not followed (§8.4) partly because the retry semantics get
 * murky and mostly because a redirect is how an allowed hostname becomes a
 * disallowed address after the check has already passed.
 *
 * ## Resolved, not just parsed
 *
 * The host is resolved to addresses and every one of them is checked. Checking
 * the *string* would refuse `http://127.0.0.1` and cheerfully accept
 * `http://localtest.me`, which is a public DNS name that resolves to 127.0.0.1.
 * A hostname is not an address, and the guard has to be about addresses.
 *
 * DNS can of course change between this check and the request — the classic
 * rebinding window — which is why the check runs again inside the delivery job,
 * immediately before the call, rather than only at save time.
 */
final class SafeUrl
{
    /**
     * CIDR ranges that are never a customer's webhook receiver.
     *
     * Loopback, link-local (which is where every cloud metadata service lives),
     * the three private IPv4 blocks, carrier-grade NAT, and the IPv6
     * equivalents.
     *
     * @var list<string>
     */
    private const BLOCKED = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '::1/128',
        'fc00::/7',
        'fe80::/10',
    ];

    /**
     * Is this a URL we are willing to POST to?
     *
     * @param  bool  $resolve  false skips DNS — for a form that must answer
     *                         instantly, and must never be the only check
     */
    public static function isAllowed(string $url, bool $resolve = true): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https') {
            return false;
        }

        $host = $parts['host'] ?? null;

        if (! is_string($host) || $host === '') {
            return false;
        }

        // A literal address in the URL is checked as itself; there is nothing
        // to resolve and nothing to rebind.
        //
        // The brackets have to come off first. `parse_url` returns the host of
        // `https://[::1]/x` as `[::1]`, which is not an IP address as far as
        // `filter_var` is concerned — so without this the loopback address
        // falls through to the hostname branch and is treated as a name that
        // merely fails to resolve.
        $literal = trim($host, '[]');

        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return ! self::isBlocked($literal);
        }

        if (! $resolve) {
            return true;
        }

        $addresses = self::resolve($host);

        // No answer is a refusal, not a pass. A hostname that does not resolve
        // cannot be delivered to anyway, and treating "unknown" as "fine" is
        // how a guard becomes decorative.
        if ($addresses === []) {
            return false;
        }

        foreach ($addresses as $address) {
            if (self::isBlocked($address)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every address a hostname answers with, v4 and v6.
     *
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if (! is_array($records)) {
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            foreach (['ip', 'ipv6'] as $key) {
                if (is_string($record[$key] ?? null)) {
                    $addresses[] = $record[$key];
                }
            }
        }

        return $addresses;
    }

    private static function isBlocked(string $address): bool
    {
        foreach (self::BLOCKED as $range) {
            if (self::inRange($address, $range)) {
                return true;
            }
        }

        return false;
    }

    /** Binary prefix comparison, so one routine handles v4 and v6 alike. */
    private static function inRange(string $address, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);

        $packedAddress = @inet_pton($address);
        $packedSubnet = @inet_pton($subnet);

        // Different families — a v4 address against a v6 range — cannot match,
        // and comparing their bytes would be meaningless rather than false.
        if ($packedAddress === false || $packedSubnet === false
            || strlen($packedAddress) !== strlen($packedSubnet)) {
            return false;
        }

        $bits = (int) $bits;
        $whole = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($whole > 0 && strncmp($packedAddress, $packedSubnet, $whole) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainder)) - 1) & 0xFF;

        return (ord($packedAddress[$whole]) & $mask) === (ord($packedSubnet[$whole]) & $mask);
    }
}
