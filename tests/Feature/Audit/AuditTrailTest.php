<?php

declare(strict_types=1);

use App\Domain\Tenancy\Actions\RevokeApiKey;
use App\Enums\ApiKeyType;
use App\Enums\ApiScope;
use App\Enums\AuditAction;
use App\Enums\DepartureStatus;
use App\Exceptions\AuditLogIsAppendOnly;
use App\Jobs\RecordAuditLogJob;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\CancellationPolicy;
use App\Models\Departure;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\artisan;

/*
|--------------------------------------------------------------------------
| The operator audit trail — ADR-0025, spec SEC-16
|--------------------------------------------------------------------------
|
| The scope decision is the thing under test as much as the mechanism. ADR-0025
| rejected the complete-history option explicitly: *"every extra row is another
| row naming a person that the retention and erasure story has to account for."*
|
| So there is a test that an ordinary field edit writes **nothing**, and it is
| as important as the ones asserting a delete writes something. A trail that
| grows on every save is the option the ADR turned down.
|
*/

/** Run a callback as an owner of a fresh tenant, with the queue faked. */
function asOperator(callable $callback): mixed
{
    $tenant = Tenant::factory()->create();
    $user = User::factory()->for($tenant)->create();

    return Tenancy::forTenant($tenant, function () use ($callback, $tenant, $user): mixed {
        auth()->login($user);

        return $callback($tenant, $user);
    });
}

/** @return list<AuditLog> */
function trailFor(Tenant $tenant): array
{
    return Tenancy::forTenant($tenant, fn (): array => AuditLog::query()->newestFirst()->get()->all());
}

it('records a soft-deleted vessel under its own SEC-16 action', function (): void {
    [$tenant, $user, $vessel] = asOperator(function (Tenant $tenant, User $user): array {
        $vessel = Vessel::factory()->create(['name' => 'Οδυσσέας 7']);
        $vessel->delete();

        return [$tenant, $user, $vessel];
    });

    $rows = trailFor($tenant);

    expect($rows)->toHaveCount(1);

    $row = $rows[0];

    expect($row->action)->toBe(AuditAction::VesselDeleted)
        ->and($row->user_id)->toBe($user->getKey())
        ->and($row->subject_type)->toBe('Vessel')
        ->and($row->subject_id)->toBe($vessel->getKey())
        // Captured at fire time, because by the time anybody reads this the row
        // is gone and cannot be asked what it was called.
        ->and($row->subject_label)->toBe('Οδυσσέας 7')
        ->and($row->context['soft'])->toBeTrue();
})->group('fast');

it('records a soft-deleted product under its own SEC-16 action', function (): void {
    [$tenant] = asOperator(function (Tenant $tenant): array {
        Product::factory()->create(['slug' => 'sunset-cruise'])->delete();

        return [$tenant];
    });

    expect(trailFor($tenant)[0]->action)->toBe(AuditAction::ProductDeleted);
})->group('fast');

it('records every other soft delete generically', function (): void {
    [$tenant] = asOperator(function (Tenant $tenant): array {
        CancellationPolicy::factory()->create()->delete();

        return [$tenant];
    });

    $row = trailFor($tenant)[0];

    // One case rather than one per model: *what* was deleted is already in
    // `subject_type`, and a `cancellation_policy.deleted` case would be the
    // same row with the information written twice.
    expect($row->action)->toBe(AuditAction::RecordDeleted)
        ->and($row->subject_type)->toBe('CancellationPolicy');
})->group('fast');

it('records an API key revocation, once, however many times it is clicked', function (): void {
    [$tenant, $key] = asOperator(function (Tenant $tenant): array {
        $key = ApiKey::factory()->create([
            'type' => ApiKeyType::Publishable,
            'scopes' => [ApiScope::ProductsRead->value],
        ]);

        $revoke = app(RevokeApiKey::class);
        $revoke($key, 'leaked in a public repository');
        // Twice, because an operator double-clicking is ordinary and "exactly
        // one row per action" is the acceptance criterion — a trail that logs
        // the click rather than the change cannot be counted.
        $revoke($key->refresh());

        return [$tenant, $key];
    });

    $rows = trailFor($tenant);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->action)->toBe(AuditAction::ApiKeyRevoked)
        ->and($rows[0]->reason)->toBe('leaked in a public repository')
        // SEC-3: the prefix identifies the key without being one.
        ->and($rows[0]->subject_label)->toBe($key->prefix)
        ->and($rows[0]->context)->not->toHaveKey('key_hash');
})->group('fast');

it('records a departure being cancelled, with the operator reason', function (): void {
    [$tenant] = asOperator(function (Tenant $tenant): array {
        $departure = Departure::factory()->create(['seats_sold' => 4]);

        $departure->forceFill([
            'status' => DepartureStatus::Cancelled,
            'cancelled_at' => Carbon::now(),
            'cancellation_note' => 'Force 7 forecast',
        ])->save();

        return [$tenant];
    });

    $row = trailFor($tenant)[0];

    // SEC-16 asks for a reason "where applicable" and this is where it applies:
    // a weather cancellation and a low-numbers one have different refund rules
    // (CXL-5), and a trail that cannot tell them apart answers nothing.
    expect($row->action)->toBe(AuditAction::DepartureCancelled)
        ->and($row->reason)->toBe('Force 7 forecast')
        ->and($row->context['seats_sold'])->toBe(4);
})->group('fast');

it('writes nothing for an ordinary edit', function (): void {
    [$tenant] = asOperator(function (Tenant $tenant): array {
        $vessel = Vessel::factory()->create();
        $vessel->forceFill(['crew_count' => 3])->save();

        return [$tenant];
    });

    // The scope decision, asserted. ADR-0025 turned down the complete-history
    // option because "most rows would never be read, the table would grow
    // without bound, and — decisively — every extra row is another row naming a
    // person that the retention and erasure story has to account for."
    expect(trailFor($tenant))->toBe([]);
})->group('fast');

it('queues the write so a failing trail cannot fail the delete', function (): void {
    Queue::fake();

    asOperator(function (): void {
        Vessel::factory()->create()->delete();
    });

    // The listener is synchronous — it has to be, to read the tenant and the
    // actor while a request still exists — and the *writing* is the job.
    Queue::assertPushed(RecordAuditLogJob::class, 1);
})->group('fast');

it('refuses to be updated or deleted', function (): void {
    [$tenant] = asOperator(function (Tenant $tenant): array {
        Vessel::factory()->create()->delete();

        return [$tenant];
    });

    Tenancy::forTenant($tenant, function (): void {
        $row = AuditLog::query()->firstOrFail();

        // ADR-0025: "an audit row that can be edited is not an audit row."
        // The migration omitting `updated_at` stops the accident; this stops
        // the deliberate attempt.
        expect(fn () => $row->update(['reason' => 'something else']))
            ->toThrow(AuditLogIsAppendOnly::class);

        expect(fn () => $row->delete())->toThrow(AuditLogIsAppendOnly::class);
    });
})->group('fast');

it('survives the actor being erased, keeping its shape and losing the person', function (): void {
    [$tenant, $user] = asOperator(function (Tenant $tenant, User $user): array {
        Vessel::factory()->create(['name' => 'Ναυσικά 12'])->delete();

        return [$tenant, $user];
    });

    Tenancy::forTenant($tenant, fn () => $user->forceDelete());

    $row = trailFor($tenant)[0];

    // ADR-0025 §3's reconciliation of seven-year retention with a GDPR erasure:
    // the actor was always a `user_id`, so the row keeps its timestamp, its
    // action and its causality and simply stops identifying a person.
    expect($row->user_id)->toBeNull()
        ->and($row->action)->toBe(AuditAction::VesselDeleted)
        ->and($row->subject_label)->toBe('Ναυσικά 12')
        ->and($row->created_at)->not->toBeNull();
})->group('fast');

it('purges past the retention window and keeps everything inside it', function (): void {
    [$tenant] = asOperator(function (Tenant $tenant): array {
        Vessel::factory()->create()->delete();

        return [$tenant];
    });

    $old = Tenancy::forTenant($tenant, fn (): AuditLog => AuditLog::factory()->create());

    // Through the query builder, because the model refuses an update — which is
    // exactly the guard being relied on everywhere else.
    Tenancy::withoutTenancy(fn () => AuditLog::query()
        ->withoutGlobalScopes()
        ->whereKey($old->getKey())
        ->update(['created_at' => Carbon::now()->subYears(8)]));

    artisan('audit:purge')->assertSuccessful();

    $remaining = trailFor($tenant);

    // Seven years is the window; the eight-year-old row goes and today's stays.
    expect($remaining)->toHaveCount(1)
        ->and($remaining[0]->getKey())->not->toBe($old->getKey());
})->group('fast');

it('refuses to purge on a nonsensical retention window', function (): void {
    config()->set('kaiki.audit.retention_days', 0);

    // An env var typo must not be able to erase seven years of evidence on the
    // next nightly tick.
    artisan('audit:purge')->assertFailed();
})->group('fast');
