<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The three fixed operator roles (data-model §2.1, ADR-0019).
 *
 * Deliberately not spatie/laravel-permission: three roles with abilities in
 * Laravel policies is less machinery than a permission package. The package is
 * conditionally approved and may only be installed once a real capability
 * cannot be expressed this way.
 */
enum Role: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Crew = 'crew';

    /** Translated label. Never a literal — every user-facing string is EL/EN. */
    public function label(): string
    {
        return __("roles.{$this->value}.label");
    }

    public function description(): string
    {
        return __("roles.{$this->value}.description");
    }

    /** Roles that may administer other users' roles. */
    public function canManageRoles(): bool
    {
        return $this === self::Owner;
    }

    /** @return array<string, string> value => label, for form selects */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            static fn (array $carry, self $role): array => $carry + [$role->value => $role->label()],
            [],
        );
    }
}
