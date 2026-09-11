<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domain\Platform\Actions\DismissAnnouncement;
use App\Models\PlatformAnnouncement;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * The banner's close button (SAA-1).
 *
 * A plain form post rather than a Livewire component: the banner is rendered
 * by a render hook on every panel page, and a component there would be a
 * second Livewire round trip on every page for a button most people press
 * once. Inside the panel's `authenticatedRoutes`, so it inherits the session
 * guard, CSRF and `ResolveTenant`.
 */
class DismissAnnouncementController
{
    public function __invoke(int $announcement): RedirectResponse
    {
        $user = Auth::user();

        abort_unless($user instanceof User && Tenancy::check(), 403);

        app(DismissAnnouncement::class)(
            PlatformAnnouncement::query()->findOrFail($announcement),
            $user,
        );

        return redirect()->back();
    }
}
