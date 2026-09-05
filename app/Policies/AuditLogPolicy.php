<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Audit\Actions\RecordAuditEntry;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Authorization\Capability;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * The audit trail: owner and manager read it, nobody writes it (ADR-0025 §4).
 *
 * ## Every write ability is false, for everyone
 *
 * Not "owner only" — **false**, including for the owner, and including
 * `create`. The ADR's sentence is the reason: *"Never updated and never deleted
 * by application code — an audit row that can be edited is not an audit row."*
 *
 * A row is written by {@see RecordAuditEntry} on
 * behalf of a queued job, which is not a person and does not go through a
 * policy. So there is no legitimate human caller for any of these, and leaving
 * the base class's answers in place would let a future bulk action or relation
 * manager offer a delete button with nothing to say it was not meant to exist.
 * {@see AuditLog} throws on both anyway; this stops the button appearing.
 *
 * ## Crew are refused the page, and that is the matrix rather than this file
 *
 * `ViewAuditLog` is held by owner and manager (TEN-8, ADR-0025 §4). Crew are
 * read-only inside a departure window and an audit trail is neither — the same
 * line the matrix draws for financials and guest documents.
 */
class AuditLogPolicy extends TenantOwnedPolicy
{
    protected function viewCapability(): Capability
    {
        return Capability::ViewAuditLog;
    }

    /**
     * The trail has no manage capability, so the base class is given the view
     * one and every write ability is then overridden to false below.
     *
     * Returning `ViewAuditLog` here rather than inventing a `ManageAuditLog`
     * that nobody holds keeps the matrix honest: a capability no role has is a
     * line somebody eventually grants.
     */
    protected function manageCapability(): Capability
    {
        return Capability::ViewAuditLog;
    }

    public function create(User $user): Response|bool
    {
        return false;
    }

    public function update(User $user, Model $model): Response|bool
    {
        return false;
    }

    public function delete(User $user, Model $model): Response|bool
    {
        return false;
    }

    public function restore(User $user, Model $model): Response|bool
    {
        return false;
    }

    public function forceDelete(User $user, Model $model): Response|bool
    {
        return false;
    }
}
