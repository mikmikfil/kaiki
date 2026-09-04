<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Observers\TenantObserver;
use App\Support\Authorization\Capability;
use Illuminate\Database\Eloquent\Model;

/**
 * The brand (TEN-8: owner and manager, never crew).
 *
 * `ManageBranding` gates **viewing** as well as editing, which is what refuses
 * crew the page. That is not a secrecy claim about a logo — the logo is on
 * every public booking page — it is that the branding screen is an editor, and
 * a read-only rendering of a form full of inputs a crew member cannot submit is
 * a support ticket waiting to happen.
 *
 * ## Two abilities are deliberately missing
 *
 * `create` and `delete` are overridden to **false** for everyone, including the
 * owner. A brand profile is created by {@see TenantObserver}
 * with the tenant and never by a person, and BRD-3's guarantee is that the row
 * always exists — so there is no legitimate caller for either. Leaving the base
 * class's answers in place would let a future relation manager or bulk action
 * offer a delete button that breaks the guarantee, and nothing would say it
 * was not meant to be there. Reset-to-defaults is an **update**, which is why
 * it is not affected.
 */
class BrandProfilePolicy extends TenantOwnedPolicy
{
    protected function viewCapability(): Capability
    {
        return Capability::ManageBranding;
    }

    protected function manageCapability(): Capability
    {
        return Capability::ManageBranding;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function restore(User $user, Model $model): bool
    {
        return false;
    }

    public function forceDelete(User $user, Model $model): bool
    {
        return false;
    }
}
