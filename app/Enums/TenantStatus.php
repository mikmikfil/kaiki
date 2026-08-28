<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Operator account status (data-model §2.1).
 *
 * `read_only` is the lapsed-subscription state: the panel still opens and the
 * widget still renders, but nothing can be written. Enforcement lives in the
 * resolution middleware (#7), not here.
 */
enum TenantStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case ReadOnly = 'read_only';
    case Suspended = 'suspended';

    public function label(): string
    {
        return __("tenant_status.{$this->value}.label");
    }

    /** May the operator write anything at all? */
    public function allowsWrites(): bool
    {
        return match ($this) {
            self::Trialing, self::Active, self::PastDue => true,
            self::ReadOnly, self::Suspended => false,
        };
    }

    /** Do guests still see the booking widget and hosted page? */
    public function allowsPublicBooking(): bool
    {
        return match ($this) {
            self::Trialing, self::Active, self::PastDue => true,
            self::ReadOnly, self::Suspended => false,
        };
    }
}
