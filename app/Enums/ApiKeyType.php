<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Publishable or secret (spec SEC-5, ADR-0013 Option A).
 *
 * The type is the **ceiling**: scopes may narrow what a key can do but never
 * widen it past what its type allows. A publishable key is designed to be
 * readable by anyone who views the page source, so its blast radius is bounded
 * by design rather than by secrecy.
 */
enum ApiKeyType: string
{
    use HasTranslatedLabel;

    case Publishable = 'publishable';
    case Secret = 'secret';

    /** The `pk_` / `sk_` marker that starts every key of this type. */
    public function keyPrefix(): string
    {
        return match ($this) {
            self::Publishable => 'pk',
            self::Secret => 'sk',
        };
    }

    /** Safe to embed in a web page where anyone can read it? */
    public function isPublic(): bool
    {
        return $this === self::Publishable;
    }

    /**
     * Scopes this type is permitted to hold at all.
     *
     * A publishable key gets the read scopes plus `bookings.write`, because
     * creating a draft booking is the one write a guest's browser must be able
     * to make. Everything else — listing bookings, reading guest personal data,
     * catalogue writes, financials — is secret-only.
     *
     * @return list<ApiScope>
     */
    public function allowedScopes(): array
    {
        return match ($this) {
            self::Publishable => [
                ApiScope::ProductsRead,
                ApiScope::AvailabilityRead,
                ApiScope::BrandingRead,
                ApiScope::BookingsWrite,
            ],
            self::Secret => ApiScope::cases(),
        };
    }

    public function permits(ApiScope $scope): bool
    {
        return in_array($scope, $this->allowedScopes(), strict: true);
    }
}
