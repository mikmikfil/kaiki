<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PlatformAnnouncement;
use App\Models\User;

/**
 * Platform announcements: the super-admin writes them, nobody else touches them
 * (SAA-1).
 *
 * Operators *read* an announcement through the banner, which asks
 * `Announcements::currentFor()` and never this policy — seeing the notice is
 * not the same permission as managing the list. Asserted rather than assumed,
 * like `TenantPolicy`: Filament allows what no policy forbids.
 */
class PlatformAnnouncementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, PlatformAnnouncement $announcement): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, PlatformAnnouncement $announcement): bool
    {
        return $user->isSuperAdmin();
    }

    /** A notice is not a record anybody has to keep; its dismissals go with it. */
    public function delete(User $user, PlatformAnnouncement $announcement): bool
    {
        return $user->isSuperAdmin();
    }
}
