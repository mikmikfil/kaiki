<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Subscription plans (brief §11). Billing behaviour arrives in M7; this exists
 * now because `tenants.plan` cannot be added later without a table rebuild on
 * SQLite (data-model §0).
 *
 * The three rules below were read by nothing until 2026-09-11; they are now
 * enforced at the point of creation through `App\Domain\Tenancy\Support\PlanLimits`
 * (SAA-8). Changing a number here changes what the panel allows — no other
 * place holds a copy.
 */
enum Plan: string
{
    use HasTranslatedLabel;

    case Trial = 'trial';
    case Solo = 'solo';
    case Fleet = 'fleet';
    case Pro = 'pro';

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
