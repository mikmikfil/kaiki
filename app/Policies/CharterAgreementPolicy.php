<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AgreementStatus;
use App\Models\User;
use App\Support\Authorization\Capability;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * The ναυλοσύμφωνο (TEN-8, SEC-3).
 *
 * A policy for a model nothing yet reads, because `PolicyCoverageTest` is right
 * to insist: Filament allows an action when no policy is registered, and the
 * M6 issue that adds the resource would inherit an unguarded model. A model
 * that is writable because nobody has thought about it yet is exactly the
 * failure SEC-3 exists to prevent.
 *
 * ## Not `ViewPaxList`, which would have been the convenient answer
 *
 * A charter agreement carries the charterer's full name, the agreed price and
 * both parties' details — it is a **contract**, not a manifest line. TEN-8 says
 * crew get *"no pricing, no financials"*, so this sits behind
 * `ViewFinancials`, beside {@see QuotePolicy}, for the same reason.
 *
 * ## Nothing may delete one
 *
 * §2.6: *"never deleted"*. An accepted agreement is evidence about a real
 * charter, and the row is the only record that the guest accepted it — which is
 * precisely what a dispute a year later is about. Voiding is an operator action
 * on the *status* of an unaccepted row, and even that has no path out of
 * `accepted` ({@see AgreementStatus}).
 */
class CharterAgreementPolicy extends TenantOwnedPolicy
{
    protected function viewCapability(): Capability
    {
        return Capability::ViewFinancials;
    }

    protected function manageCapability(): Capability
    {
        return Capability::ManageBookings;
    }

    /** §2.6: never deleted, by anybody, ever. */
    public function delete(User $user, Model $model): Response|bool
    {
        return false;
    }

    public function forceDelete(User $user, Model $model): Response|bool
    {
        return false;
    }
}
