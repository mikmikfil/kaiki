<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Support;

/**
 * What a hostname points at (ADR-0010, HOS-3).
 *
 * ## An interface, because the alternative is untestable
 *
 * Verification asks the internet a question. A test that called
 * `dns_get_record()` directly would be a test that passes on a laptop with a
 * working resolver, fails on a runner behind a proxy, and — worse — could only
 * assert the positive case, because nobody can make a real registrar answer
 * "not yet" on demand.
 *
 * So the lookup is a port with one real implementation and a fake. Every rule
 * about what counts as verified is then testable: a CNAME that points somewhere
 * else, a hostname that does not resolve at all, a domain that resolved
 * yesterday and does not today.
 */
interface DnsLookup
{
    /**
     * The CNAME targets and A records `$hostname` resolves to, lower-cased and
     * without their trailing dots.
     *
     * A hostname that does not resolve returns an empty list rather than
     * throwing: "not yet" is the ordinary state of a domain an operator
     * configured four minutes ago, not an error.
     *
     * @return list<string>
     */
    public function targetsFor(string $hostname): array;
}
