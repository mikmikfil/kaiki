<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Support\ImpersonationSession;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * Back to being the platform owner (TEN-7, SAA-2).
 *
 * Called three ways, and they must all end in the same state: the button on the
 * banner, the sixty-minute deadline passing, and a super-admin who simply signs
 * out. That is why the session keys are cleared **before** anything else can
 * fail — a half-ended impersonation is worse than either end of it, because the
 * banner would be gone while the session it described was still live.
 *
 * ## No audit row here, deliberately
 *
 * SAA-2 asks for who, which tenant, when and why. All four are on the row
 * `StartImpersonation` wrote, together with the deadline — so an end is already
 * implied by it, and a second row would double every impersonation in a trail
 * that ADR-0025 deliberately keeps small. If "how long they actually stayed"
 * ever matters, it belongs as a column on the existing row rather than as a row
 * of its own.
 *
 * ## An expired session logs nobody back in
 *
 * When the deadline has passed the operator's session is ended and nothing is
 * resumed: the platform owner signs in again as themselves. Restoring a session
 * that expired *because* it was unattended would be the one case where this
 * quietly extends itself.
 */
final class StopImpersonation
{
    public function __invoke(): ?User
    {
        $impersonator = ImpersonationSession::impersonator();
        $expired = ImpersonationSession::hasExpired();

        ImpersonationSession::forget();

        if ($impersonator === null || $expired) {
            Auth::logout();
            Session::regenerate();

            return null;
        }

        Session::regenerate();
        Auth::login($impersonator);
        ImpersonationSession::rememberPasswordOf($impersonator);

        return $impersonator;
    }
}
