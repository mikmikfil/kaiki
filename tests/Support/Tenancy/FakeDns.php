<?php

declare(strict_types=1);

namespace Tests\Support\Tenancy;

use App\Domain\Tenancy\Actions\VerifyDomain;
use App\Domain\Tenancy\Support\DnsLookup;

/**
 * The internet, answering on demand.
 *
 * Every rule in {@see VerifyDomain} is about what
 * the DNS said, and none of them could be asserted against a real resolver: a
 * test cannot make a registrar answer "not yet", and a test that called
 * `dns_get_record()` would pass on a laptop and fail on a runner behind a proxy.
 *
 * `points($hostname)` with no targets is the ordinary state of a domain
 * somebody configured four minutes ago — not an error, and the case the
 * "a verified domain that stops resolving keeps serving" test is built on.
 */
final class FakeDns implements DnsLookup
{
    /** @var array<string, list<string>> */
    private array $records = [];

    public function points(string $hostname, string ...$targets): self
    {
        $this->records[strtolower($hostname)] = array_map('strtolower', $targets);

        return $this;
    }

    /** @return list<string> */
    public function targetsFor(string $hostname): array
    {
        return $this->records[strtolower($hostname)] ?? [];
    }
}
