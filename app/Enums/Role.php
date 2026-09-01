<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

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
}
