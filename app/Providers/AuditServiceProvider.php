<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\DepartureStatus;
use App\Events\DepartureCancelled;
use App\Events\RecordSoftDeleted;
use App\Models\AuditLog;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Departure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the audit trail to the events that feed it (ADR-0025, spec SEC-16).
 *
 * Three registrations, and each covers a different half of ADR-0025's scope
 * decision — *"SEC-16's five named actions, plus every soft delete and every
 * operator override that carries a reason"*.
 */
final class AuditServiceProvider extends ServiceProvider
{
    /**
     * Models that are tenant-owned and soft-deletable and still must not be
     * audited.
     *
     * `AuditLog` is the obvious one and the reason is not obvious: it cannot be
     * deleted at all, so a listener on its deletion is unreachable — but if the
     * append-only guard were ever loosened, an audited audit row would recurse.
     * Naming it here costs nothing and removes the question.
     *
     * @var list<class-string<Model>>
     */
    private const NEVER_AUDITED = [AuditLog::class];

    public function boot(): void
    {
        // 1. `RecordAuditLog` is **not registered here**, and that is a fix
        //    rather than an omission.
        //
        //    Laravel 11+ discovers listeners in `app/Listeners` from their
        //    type hints, so `handle(Auditable $event)` is already bound to the
        //    interface — and adding an explicit `Event::listen()` on top of
        //    that registered it twice and wrote **two identical rows for one
        //    delete**. In an audit trail that is worse than a missing row: a
        //    trail that double-counts cannot be counted at all.
        //
        //    `AuditListenerRegistrationTest` asserts the binding exists, so
        //    discovery being turned off is a red test rather than a trail that
        //    silently stops.

        // 2. Every soft delete, from every path.
        //
        //    A wildcard on Eloquent's own event rather than an observer on
        //    twenty models: a soft delete happens from a Filament resource, a
        //    Domain Action, an importer and a console command, and a list of
        //    models is a list that goes stale the first time M2 adds a table.
        //    ADR-0025 says "every soft delete" and this is the only spelling
        //    that stays true without maintenance.
        Event::listen('eloquent.deleted: *', function (string $event, array $models): void {
            foreach ($models as $model) {
                if ($model instanceof Model && $this->isAuditableSoftDelete($model)) {
                    RecordSoftDeleted::dispatch($model, $this->labelFor($model));
                }
            }
        });

        // 3. A departure being called off (SEC-16, AVL-28).
        //
        //    Observed rather than fired from an Action, because M1 has no
        //    cancellation *workflow* at all — CXL-6 and OPS-6 are M5. Watching
        //    the status transition means the workflow that arrives then is
        //    audited by doing what it was going to do anyway, which is the
        //    ADR's argument for events in the first place.
        //
        //    Note the signature. A **wildcard** listener is handed the event
        //    name and an array payload; a **named** one is handed the dispatch
        //    arguments spread — which for a model event is the model itself.
        //    Copying the shape from the wildcard above fails twice over, first
        //    with an `ArgumentCountError` and then with a `TypeError`, and both
        //    only at the moment a departure is actually cancelled.
        Event::listen('eloquent.updated: ' . Departure::class, function (Departure $departure): void {
            if ($this->justCancelled($departure)) {
                DepartureCancelled::dispatch($departure, $departure->cancellation_note);
            }
        });
    }

    /**
     * A soft delete worth recording: tenant-owned, actually soft, not excluded.
     *
     * **Tenant-owned is the load-bearing condition.** `audit_logs.tenant_id` is
     * `NOT NULL`, so a platform-owned model — `VatRate` is the only one today —
     * has no tenant to file the row under. It is also the right answer rather
     * than merely the possible one: the trail is *the operator's*, and a
     * platform action against a shared reference table is ARC-21's business,
     * which ADR-0025 puts explicitly out of scope.
     */
    private function isAuditableSoftDelete(Model $model): bool
    {
        if (in_array($model::class, self::NEVER_AUDITED, true)) {
            return false;
        }

        $traits = class_uses_recursive($model);

        if (! isset($traits[SoftDeletes::class], $traits[BelongsToTenant::class])) {
            return false;
        }

        // A force delete fires the same event. It is not "a soft delete" and
        // ADR-0025's scope says soft — a force delete in M1 only happens from
        // a console, and giving it the same code would make the `soft` flag in
        // `context` a lie.
        return method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting();
    }

    /** Did this update turn a live departure into a cancelled one? */
    private function justCancelled(Departure $departure): bool
    {
        return $departure->status === DepartureStatus::Cancelled
            && $departure->getOriginal('status') !== DepartureStatus::Cancelled->value;
    }

    /**
     * What the row was called, for a reader who will never see it again.
     *
     * `name` where a model has one, then `title`, then the slug — the three
     * attributes the catalogue actually uses. Null rather than a fabricated
     * "Vessel #418", which reads as information and is not.
     */
    private function labelFor(Model $model): ?string
    {
        foreach (['name', 'title', 'slug'] as $attribute) {
            $value = $model->getAttribute($attribute);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
