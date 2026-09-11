<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\Authorization\Capability;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Who may import, and who may read an import's log (SAA-13, TEN-8).
 *
 * Owner only, both ways. The log is a list of every past customer's name and
 * email the files contained, which is not something to widen by accident.
 *
 * An import is never edited as a record — its mapping changes through the
 * review screen's own action — and never deleted: the rows are the answer to
 * "where did these forty bookings come from?" a season later.
 */
class ImportJobPolicy extends TenantOwnedPolicy
{
    protected function viewCapability(): Capability
    {
        return Capability::ImportData;
    }

    protected function manageCapability(): Capability
    {
        return Capability::ImportData;
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
