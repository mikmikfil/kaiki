<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Support;

use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * What the session remembers while a super-admin is signed in as an operator
 * (spec TEN-7, SAA-2).
 *
 * ## The acting user really is the operator
 *
 * There is no "super-admin with borrowed powers" mode. `StartImpersonation`
 * logs the platform owner **in as one of the operator's own people**, and the
 * whole panel — the tenant scope, {@see User::hasCapability()}, every policy —
 * behaves exactly as it does for that person, because for the next hour it is
 * that person.
 *
 * That is deliberate and it is the safer of the two designs. The alternative —
 * teaching a super-admin to carry operator capabilities — would mean every
 * policy in the codebase growing a second path that is exercised by nobody in
 * normal use, and `User::hasCapability()` says outright why it refuses:
 * *«A super-admin is not an operator and holds no operator capabilities.»*
 *
 * What this class holds is the way **back**, and the three facts TEN-7 wants
 * visible while it lasts: who is really here, which operator they are inside,
 * and when it ends by itself.
 *
 * ## It expires on its own
 *
 * SAA-2 sets sixty minutes and Mike kept it on 2026-09-23. The deadline is
 * stored rather than computed from the start, so lengthening the default later
 * cannot silently extend a session that is already running.
 *
 * `isActive()` is false the moment the deadline passes, so every read of this
 * class already treats an expired session as no session at all — the
 * middleware that signs the person back out is what makes that visible, not
 * what makes it true.
 */
final class ImpersonationSession
{
    /** SAA-2's default, and Mike's answer on 2026-09-23 when asked. */
    public const MINUTES = 60;

    private const IMPERSONATOR = 'kaiki.impersonator_id';

    private const TENANT = 'kaiki.impersonated_tenant_id';

    private const EXPIRES = 'kaiki.impersonation_expires_at';

    public static function begin(User $impersonator, int $tenantId): void
    {
        Session::put(self::IMPERSONATOR, $impersonator->getKey());
        Session::put(self::TENANT, $tenantId);
        Session::put(self::EXPIRES, Carbon::now()->addMinutes(self::MINUTES)->toIso8601String());
    }

    /**
     * Put the new person's password fingerprint in the session after a switch.
     *
     * Both panels run `AuthenticateSession`, which signs a session out when the
     * `password_hash_web` it carries is not the signed-in person's. It refreshes
     * that value at the end of every request it wraps — but the switch happens
     * inside a Livewire action, and `/livewire/update` does not run it. So the
     * session kept the platform owner's fingerprint, the very next page compared
     * it with the operator's password, and «Σύνδεση ως» landed on `/app/login`
     * (found 2026-09-23, fixed 2026-09-24). The way back had the same flaw the
     * other way round.
     */
    public static function rememberPasswordOf(User $user): void
    {
        $guard = Auth::guard();
        $hash = (string) $user->getAuthPassword();

        if ($guard instanceof SessionGuard) {
            $hash = $guard->hashPasswordForCookie($hash);
        }

        Session::put('password_hash_' . Auth::getDefaultDriver(), $hash);
    }

    public static function forget(): void
    {
        Session::forget([self::IMPERSONATOR, self::TENANT, self::EXPIRES]);
    }

    /** Is somebody signed in as somebody else right now? */
    public static function isActive(): bool
    {
        return self::impersonatorId() !== null && ! self::hasExpired();
    }

    public static function hasExpired(): bool
    {
        $expires = self::expiresAt();

        return $expires !== null && $expires->isPast();
    }

    public static function impersonatorId(): ?int
    {
        $id = Session::get(self::IMPERSONATOR);

        return is_numeric($id) ? (int) $id : null;
    }

    public static function tenantId(): ?int
    {
        $id = Session::get(self::TENANT);

        return is_numeric($id) ? (int) $id : null;
    }

    public static function expiresAt(): ?Carbon
    {
        $at = Session::get(self::EXPIRES);

        return is_string($at) ? Carbon::parse($at) : null;
    }

    /**
     * Whole minutes left, never below zero.
     *
     * Rounded **up**, because the banner says «απομένουν :minutes λεπτά» and a
     * session with forty seconds left saying «0 λεπτά» reads as already over.
     */
    public static function minutesLeft(): int
    {
        $expires = self::expiresAt();

        if ($expires === null) {
            return 0;
        }

        return max(0, (int) ceil(Carbon::now()->diffInSeconds($expires, false) / 60));
    }

    /** The platform owner behind the operator on screen, if there is one. */
    public static function impersonator(): ?User
    {
        $id = self::impersonatorId();

        return $id === null ? null : User::query()->find($id);
    }
}
