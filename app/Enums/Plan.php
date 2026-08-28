<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Subscription plans (brief §11). Billing behaviour arrives in M7; this exists
 * now because `tenants.plan` cannot be added later without a table rebuild on
 * SQLite (data-model §0).
 */
enum Plan: string
{
    case Trial = 'trial';
    case Solo = 'solo';
    case Fleet = 'fleet';
    case Pro = 'pro';

    public function label(): string
    {
        return __("plans.{$this->value}.label");
    }

    /** Vessel allowance. `null` = unlimited. */
    public function vesselLimit(): ?int
    {
        return match ($this) {
            self::Trial, self::Solo => 1,
            self::Fleet => 5,
            self::Pro => null,
        };
    }

    public function allowsCustomDomain(): bool
    {
        return $this === self::Pro;
    }

    public function allowsWebhooks(): bool
    {
        return $this === self::Pro;
    }
}
