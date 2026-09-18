<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use App\Support\Authorization\Capability;

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
    use HasTranslatedLabel;

    case Owner = 'owner';
    case Manager = 'manager';
    case Crew = 'crew';

    /**
     * The longer explanation shown beside the role in the panel.
     *
     * On `Role` rather than on the trait: it is the only enum with
     * descriptions, and a trait method that six enums answer with a dotted key
     * is an invitation for an M1 resource to render one.
     */
    public function description(): string
    {
        return $this->line('description');
    }

    /** Roles that may administer other users' roles. */
    public function canManageRoles(): bool
    {
        return $this === self::Owner;
    }

    /**
     * How much this role can do, as a number (2026-09-18).
     *
     * The three are strictly nested — every capability a crew member holds is
     * also a manager's, and every capability a manager holds is also an
     * owner's; see {@see Capability::heldBy()}. That
     * is what makes "the highest of them" a meaningful thing to say, and what
     * made the old multi-select pointless: «owner and crew» granted precisely
     * what «owner» already did.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Owner => 3,
            self::Manager => 2,
            self::Crew => 1,
        };
    }

    /**
     * The one role that speaks for a set of them.
     *
     * `role_assignments` still holds a row per role, and the seeds, the API and
     * anything restoring an old account may write several — so this is how a
     * screen that asks for one role reads a person who has two.
     *
     * @param  iterable<Role>  $roles
     */
    public static function highest(iterable $roles): ?self
    {
        $highest = null;

        foreach ($roles as $role) {
            if (! $highest instanceof self || $role->rank() > $highest->rank()) {
                $highest = $role;
            }
        }

        return $highest;
    }
}
