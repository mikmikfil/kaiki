<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Actions;

use App\Domain\Audit\Actions\RecordAuditEntry;
use App\Domain\Audit\Data\AuditEntryData;
use App\Domain\Tenancy\Support\ImpersonationSession;
use App\Enums\AuditAction;
use App\Exceptions\ImpersonationRefused;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * A super-admin signs in as one of an operator's people (TEN-7, SAA-2).
 *
 * Mike, 2026-09-23: *«θέλω ως admin να συνδέομαι ανά πάσα στιγμή στον operator
 * και να μπορώ να φτιάχνω διάφορα»* — full access, not read-only, and sixty
 * minutes.
 *
 * ## Why it was refused until now, and what changed
 *
 * `User::hasCapability()` returns false for a super-admin and says why:
 * *«Reaching an operator's data is impersonation (TEN-7, M7), an audited action
 * rather than an implicit privilege.»* That was half a decision — the refusal
 * shipped, the audited action did not, and `admin@kaiki.example` got a 403 at
 * `/app` with no way forward. This is the other half.
 *
 * ## Three gates, and none of them is decoration
 *
 * - **Super-admin only.** Anyone else asking is a bug or an attack.
 * - **A target inside the tenant.** Impersonating across operators is the one
 *   thing tenancy exists to prevent, so the target's `tenant_id` is checked
 *   against the tenant rather than trusted from the form.
 * - **A reason, in writing.** SAA-2 wants *why* in the trail, and a free-text
 *   box that can be left empty is a box everybody leaves empty.
 *
 * ## The audit row is written before the switch
 *
 * Deliberately: from the next line on, `auth()->id()` is the operator's person,
 * and an entry written after would name them as the actor of their own
 * impersonation. Written first, it names the platform owner — which is the
 * entire point of writing it.
 */
final class StartImpersonation
{
    public function __construct(private readonly RecordAuditEntry $audit) {}

    /**
     * @throws ImpersonationRefused
     */
    public function __invoke(User $impersonator, User $target, Tenant $tenant, string $reason): void
    {
        if (! $impersonator->isSuperAdmin()) {
            throw ImpersonationRefused::notPermitted();
        }

        if ((int) $target->tenant_id !== (int) $tenant->getKey()) {
            throw ImpersonationRefused::wrongTenant();
        }

        if (trim($reason) === '') {
            throw ImpersonationRefused::noReason();
        }

        if ($target->is($impersonator)) {
            throw ImpersonationRefused::yourself();
        }

        $this->audit->__invoke(
            new AuditEntryData(
                action: AuditAction::ImpersonationStarted,
                subjectType: 'User',
                subjectId: (int) $target->getKey(),
                subjectLabel: (string) $target->name,
                reason: trim($reason),
                context: [
                    'impersonator_id' => (int) $impersonator->getKey(),
                    'impersonator_email' => (string) $impersonator->email,
                    'minutes' => ImpersonationSession::MINUTES,
                ],
            ),
            $tenant,
            userId: (int) $impersonator->getKey(),
            ipAddress: request()->ip(),
        );

        // The id is read before the guard changes it, and the session is
        // regenerated for the same reason any sign-in does: the id that
        // belonged to the platform owner must not go on to identify a session
        // acting as somebody else.
        $impersonatorId = $impersonator->getKey();

        Session::regenerate();

        Auth::login($target);

        ImpersonationSession::begin(
            User::query()->findOrFail($impersonatorId),
            (int) $tenant->getKey(),
        );
    }
}
