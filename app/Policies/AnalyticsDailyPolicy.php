<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Analytics\Actions\CountAnalyticsEvent;
use App\Models\User;
use App\Support\Authorization\Capability;
use Illuminate\Auth\Access\Response;

/**
 * The counted visits (ADR-0032, OPS-23).
 *
 * `ManageBookings`, which is the same line the statistics page itself draws:
 * owner and manager, never crew. A count of page views is a business figure,
 * and TEN-8 gives crew a passenger list and nothing else.
 *
 * ## Every write is refused, to everybody
 *
 * These rows are made by {@see CountAnalyticsEvent} as an atomic increment and
 * are never edited by a person. There is no screen that could create, update or
 * delete one, and a policy that allowed it would be permission for a button
 * that does not exist — the kind of gap that is only noticed once something
 * else starts using the model. The base class's writes are overridden rather
 * than inherited so that stays true if a resource is ever generated for this
 * table by habit.
 */
class AnalyticsDailyPolicy extends TenantOwnedPolicy
{
    protected function viewCapability(): Capability
    {
        return Capability::ManageBookings;
    }

    protected function manageCapability(): Capability
    {
        return Capability::ManageBookings;
    }

    public function create(User $user): Response|bool
    {
        return false;
    }

    public function update(User $user, mixed $record = null): Response|bool
    {
        return false;
    }

    public function delete(User $user, mixed $record = null): Response|bool
    {
        return false;
    }
}
