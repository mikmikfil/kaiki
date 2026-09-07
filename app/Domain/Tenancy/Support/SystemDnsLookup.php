<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Support;

/**
 * The real resolver, asking the system's own (ADR-0010).
 *
 * ## Both CNAME and A, because operators configure both
 *
 * ADR-0010 asks for a CNAME, and that is what the panel tells an operator to
 * create. But a registrar that does not allow a CNAME on an apex, or an operator
 * who read a different guide, will point an **A record** at the platform's
 * address instead — and refusing to verify that would be refusing a domain that
 * works.
 *
 * So both are collected and the caller decides. `dns_get_record` is asked for
 * each type separately rather than with `DNS_ANY`, which is unreliable across
 * resolvers and is the reason a "why does verification fail for this one
 * domain" bug takes a day to find.
 *
 * ## Failures are an empty list, never an exception
 *
 * A hostname that does not resolve is the **ordinary** state of a domain
 * somebody set up four minutes ago. `dns_get_record` emits a warning and returns
 * `false` for NXDOMAIN, so the warning is suppressed deliberately: it is not an
 * error condition, it is the answer.
 */
final class SystemDnsLookup implements DnsLookup
{
    /** @return list<string> */
    public function targetsFor(string $hostname): array
    {
        $hostname = trim($hostname);

        if ($hostname === '') {
            return [];
        }

        $targets = [];

        foreach ([DNS_CNAME, DNS_A] as $type) {
            /** @var list<array<string, mixed>>|false $records */
            $records = @dns_get_record($hostname, $type);

            if ($records === false) {
                continue;
            }

            foreach ($records as $record) {
                foreach (['target', 'ip'] as $key) {
                    $value = $record[$key] ?? null;

                    if (is_string($value) && $value !== '') {
                        $targets[] = strtolower(rtrim($value, '.'));
                    }
                }
            }
        }

        return array_values(array_unique($targets));
    }
}
