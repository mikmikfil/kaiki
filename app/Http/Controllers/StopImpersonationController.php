<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Tenancy\Actions\StopImpersonation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * «Έξοδος» on the impersonation banner (TEN-7, SAA-2).
 *
 * One line of work, and the reason it is a controller rather than a Livewire
 * action is in the route file: the banner has to work on a page whose Livewire
 * component has already failed.
 *
 * **No authorization check of its own.** Ending an impersonation is not a
 * privilege — it is the way back, and the only person who can reach it is
 * somebody already holding the session it ends. A gate here could only ever
 * trap somebody inside an account that is not theirs.
 */
final class StopImpersonationController extends Controller
{
    public function __invoke(StopImpersonation $stop): RedirectResponse
    {
        $impersonator = $stop();

        // Back to the platform panel when there is somebody to be again; to the
        // sign-in page when the hour ran out, because `StopImpersonation`
        // deliberately resumes nobody in that case.
        return $impersonator instanceof User
            ? redirect()->route('filament.admin.pages.dashboard')
            : redirect()->route('filament.admin.auth.login');
    }
}
