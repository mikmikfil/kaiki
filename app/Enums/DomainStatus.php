<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Verification state of an operator's custom domain (ADR-0010 Option A).
 *
 * Only `Verified` resolves a tenant. A `Pending` row exists so the operator can
 * see what they still have to do in DNS, and a `Disabled` one so a domain can
 * be switched off without losing its history — neither is a claim of ownership,
 * and resolving on either would let anyone point a hostname at the platform and
 * be served another operator's data.
 */
enum DomainStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Failed = 'failed';
    case Disabled = 'disabled';

    public function label(): string
    {
        return __("domains.status.{$this->value}");
    }

    /** May a request on this hostname resolve its tenant? */
    public function resolvesTenant(): bool
    {
        return $this === self::Verified;
    }
}
