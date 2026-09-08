<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ExportJob;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Tenancy;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Who may ask for a CSV, and who may read the record of one (TEN-8, OPS-18).
 *
 * ## `ExportData` is owner and manager — crew are refused
 *
 * The matrix already said so; this is the first resource that reads it. A
 * bookings CSV is financials — totals, VAT, what was paid and refunded — and a
 * guests CSV is every passenger's name and date of birth for a whole season.
 * Crew are read-only inside a departure window, and a file covering four
 * seasons is neither.
 *
 * The narrower line matters more than it looks: crew *do* hold `ViewManifest`,
 * so somebody comparing the two might reasonably expect them to hold this. The
 * difference is scope. A manifest is one sailing they are about to work; an
 * export is the whole business.
 *
 * ## Nothing here is editable or deletable, by anyone
 *
 * An export row is the log OPS-18 asks for. A log with a delete button is not
 * a log — the same argument {@see AuditLogPolicy} makes, arrived at from the
 * other direction: that trail records disclosures, and this one records who
 * took a spreadsheet of guest contact details out of the system and whether
 * anybody ever fetched it.
 *
 * The file is removed on expiry regardless, by `PurgeExpiredExportsJob`. What
 * cannot be removed is the fact that it existed.
 *
 * ## Read-only mode refuses a new export, and that follows TEN-9 as written
 *
 * *"blocks all writes in `/app`"*, and creating an export is a write. It is
 * worth saying plainly that this is a defensible reading rather than an obvious
 * one: an operator whose subscription has lapsed arguably has the strongest
 * claim of anyone to a copy of their own books, and a product that holds them
 * shut is a product they leave angry. Whether SAA-7's read-only state should
 * carve out data export is a product decision, not one to take inside a policy
 * class — it is recorded in `docs/BUILD-LOG.md` as an open question.
 *
 * **Downloading an existing export is unaffected**, because it is a read and
 * never reaches this class.
 */
class ExportJobPolicy extends TenantOwnedPolicy
{
    protected function viewCapability(): Capability
    {
        return Capability::ExportData;
    }

    protected function manageCapability(): Capability
    {
        return Capability::ExportData;
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

    /**
     * Downloading is `view` on the row.
     *
     * The controller asks the capability directly rather than going through
     * here, because a URL outlives the page that produced it and the check has
     * to be true at the moment of the request. This stays consistent with it so
     * that a future Filament action cannot offer a download the controller
     * would then refuse.
     */
    public function view(User $user, Model $model): bool
    {
        return $model instanceof ExportJob
            && $user->hasCapability(Capability::ExportData)
            && $model->tenant_id === Tenancy::id();
    }
}
