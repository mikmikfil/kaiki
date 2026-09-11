<?php

declare(strict_types=1);

namespace App\Domain\Platform\Support;

use App\Models\PlatformAnnouncement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Which announcement, if any, a person sees at the top of their panel (SAA-1).
 *
 * ## The newest current one, or nothing
 *
 * Only the most recent announcement that is switched on and inside its dates is
 * ever a candidate. If this person closed it, they see **nothing** — not the
 * one before it. "Until the next announcement" is the rule: an older notice
 * the platform owner has since replaced should not resurface because somebody
 * dismissed its successor.
 */
final class Announcements
{
    public static function currentFor(User $user, ?Carbon $now = null): ?PlatformAnnouncement
    {
        $latest = PlatformAnnouncement::query()
            ->current($now)
            ->orderByDesc('id')
            ->first();

        if (! $latest instanceof PlatformAnnouncement) {
            return null;
        }

        $dismissed = DB::table('platform_announcement_dismissals')
            ->where('platform_announcement_id', $latest->getKey())
            ->where('user_id', $user->getKey())
            ->exists();

        return $dismissed ? null : $latest;
    }
}
