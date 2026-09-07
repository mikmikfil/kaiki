<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\Authorization\Capability;
use Illuminate\Auth\Access\Response;

/**
 * The operator's FAQ (TEN-8: owner and manager, never crew).
 *
 * `ManageBranding`, the same capability as the home page of #102 and for the
 * same reason: an FAQ entry does not change what is for sale, what it costs or
 * whether a boat can sail — it changes what the business says about itself in
 * public. Whoever the operator trusts with the logo and the front page is who
 * writes the answer to "what happens if it rains".
 *
 * `ManageCatalogue` would draw the identical line in practice — the matrix
 * grants both to owner and manager — so the choice is about which sentence the
 * next person reads, not about which roles get in. Crew reach neither, which is
 * the requirement.
 */
class FaqPolicy extends TenantOwnedPolicy
{
    protected function viewCapability(): Capability
    {
        return Capability::ManageBranding;
    }

    protected function manageCapability(): Capability
    {
        return Capability::ManageBranding;
    }

    /**
     * Drag-and-drop ordering in the table (`FaqResource`).
     *
     * Filament calls `can('reorder')` before it renders the handles, and a
     * policy with no such method denies — so the feature disappears with no
     * error anywhere, which arrives as a bug report about missing arrows rather
     * than about permissions.
     *
     * It writes `sort_order`, so it is a write: read-only mode refuses it with
     * the same sentence as every other write, and crew never reach it.
     */
    public function reorder(User $user): Response|bool
    {
        return $this->refusalWhenReadOnly()
            ?? $user->hasCapability($this->manageCapability());
    }
}
