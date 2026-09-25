<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\Actions\StopImpersonation;
use App\Domain\Tenancy\Support\ImpersonationSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The sixty minutes, enforced on the way in (TEN-7, SAA-2).
 *
 * `ImpersonationSession::isActive()` is already false once the deadline passes,
 * so nothing *reads* an expired session as live. What this adds is ending it:
 * without it the operator's session would keep working — the guard has them
 * signed in — while the banner that explained why had disappeared. That is the
 * worst of the three possible states, because it is the one nobody can see.
 *
 * On every panel request rather than on a schedule: a time limit that depends
 * on a queue worker running is a time limit an operator cannot rely on, and
 * SAA-2's *"time-limited"* is a promise made to them rather than to us.
 *
 * The person is sent to the sign-in page, not back to the platform panel:
 * {@see StopImpersonation} deliberately resumes nobody when the deadline was
 * what ended it.
 */
class EndExpiredImpersonation
{
    public function __construct(private readonly StopImpersonation $stop) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (ImpersonationSession::impersonatorId() !== null && ImpersonationSession::hasExpired()) {
            $this->stop->__invoke();

            return redirect()->guest(route('filament.admin.auth.login'));
        }

        return $next($request);
    }
}
