<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use App\Models\PlatformAnnouncement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One person closes one announcement (SAA-1).
 *
 * `insertOrIgnore` against the unique index, so a double click — or the same
 * form submitted from two tabs — is a no-op rather than an error page.
 */
final class DismissAnnouncement
{
    public function __invoke(PlatformAnnouncement $announcement, User $user): void
    {
        DB::table('platform_announcement_dismissals')->insertOrIgnore([
            'platform_announcement_id' => $announcement->getKey(),
            'user_id' => $user->getKey(),
            'dismissed_at' => Carbon::now(),
        ]);
    }
}
