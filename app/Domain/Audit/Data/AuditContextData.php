<?php

declare(strict_types=1);

namespace App\Domain\Audit\Data;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Spatie\LaravelData\Data;

/**
 * Who did it, for which operator, and from where (ADR-0025, spec SEC-16).
 *
 * ## Captured in the request, never on the worker
 *
 * The whole reason this class exists. A queued listener is constructed **on the
 * worker**, where `Auth::id()` is null and `Tenancy::current()` holds whatever
 * the previous job left behind. Reading either there attributes the action to
 * nobody at best and to the **wrong operator** at worst, which is the
 * cross-tenant write ADR-0001 and #8's isolation gate exist to prevent.
 *
 * So the audit listener is synchronous and does exactly one thing: capture this
 * and dispatch a job carrying it. The job is what is queued.
 *
 * ## The actor is an id, and that is the retention story
 *
 * ADR-0025 §3 reconciles seven-year retention with a GDPR erasure request by
 * *"storing the actor as a `user_id`, never a name or an email"*. An erasure
 * anonymises the user row and the trail keeps its shape, its timestamps and its
 * causality while no longer identifying a person. Putting a name here would
 * quietly undo that, seven years at a time.
 */
final class AuditContextData extends Data
{
    public function __construct(
        public readonly ?int $tenantId,
        public readonly ?int $userId,
        public readonly ?string $ipAddress,
    ) {}

    /** Read the ambient request context, while there still is one. */
    public static function capture(): self
    {
        $user = Auth::user();

        return new self(
            tenantId: Tenancy::current()?->getKey(),
            userId: $user instanceof User ? $user->getKey() : null,
            // Null outside a request — a console command or a test — rather
            // than a fabricated `127.0.0.1`, which would read as a real
            // machine somebody could go looking for.
            ipAddress: app()->runningInConsole() ? null : Request::ip(),
        );
    }

    public function tenant(): ?Tenant
    {
        if ($this->tenantId === null) {
            return null;
        }

        /** @var Tenant|null $tenant */
        $tenant = Tenancy::withoutTenancy(fn (): ?Tenant => Tenant::query()->find($this->tenantId));

        return $tenant;
    }
}
