<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\Authorization\Capability;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * An import's per-record log (SAA-15).
 *
 * Read by whoever may read the import — the owner — and written only by the
 * importer itself. A row is the answer to "what became of WooCommerce booking
 * 5501", and a log a person could edit is not one.
 */
class ImportJobRowPolicy extends TenantOwnedPolicy
{
    protected function viewCapability(): Capability
    {
        return Capability::ImportData;
    }

    protected function manageCapability(): Capability
    {
        return Capability::ImportData;
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
