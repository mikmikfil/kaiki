<?php

declare(strict_types=1);

use App\Data\Pricing\CancellationPolicyData;
use App\Domain\Catalog\Actions\SaveCancellationPolicy;
use App\Enums\Role;
use App\Exceptions\MissingTranslationException;
use App\Models\CancellationPolicy;
use App\Models\CancellationPolicyTier;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Database\QueryException;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| Cancellation policies — spec CAT-13, CXL-1, CXL-3, TEN-8
|--------------------------------------------------------------------------
|
| Two invariants carry this table. **Exactly one default per tenant**, because
| `products.cancellation_policy_id` is nullable and null means "the tenant
| default" — two defaults make a product's terms depend on which row a query
| returned first, and none leaves products with no terms at all. And **one rung
| per threshold**, because evaluation picks the largest qualifying rung and two
| at the same threshold makes that ambiguous.
|
*/

function inTenant(callable $callback): mixed
{
    return Tenancy::forTenant(Tenant::factory()->create(), $callback);
}

it('makes the first policy the default even when nobody ticked the box', function (): void {
    // Otherwise the first product created before anyone thinks about
    // cancellation terms resolves to no policy at all.
    inTenant(function (): void {
        $policy = app(SaveCancellationPolicy::class)(
            new CancellationPolicy,
            ['name' => ['el' => 'Βασική', 'en' => 'Standard'], 'is_default' => false],
        );

        expect($policy->is_default)->toBeTrue();
    });
})->group('fast');

it('demotes the previous default when another is promoted', function (): void {
    inTenant(function (): void {
        $first = app(SaveCancellationPolicy::class)(
            new CancellationPolicy,
            ['name' => ['el' => 'Πρώτη', 'en' => 'First'], 'is_default' => true],
        );

        $second = app(SaveCancellationPolicy::class)(
            new CancellationPolicy,
            ['name' => ['el' => 'Δεύτερη', 'en' => 'Second'], 'is_default' => true],
        );

        expect($second->is_default)->toBeTrue()
            ->and($first->refresh()->is_default)->toBeFalse()
            // The invariant stated directly: exactly one, never two, never zero.
            ->and(CancellationPolicy::query()->default()->count())->toBe(1);
    });
})->group('fast');

it('keeps each tenant its own default', function (): void {
    // The demotion is a bare `update()` on a tenant-owned model, so it rides
    // the global scope. If it did not, promoting a policy would silently demote
    // another operator's default — a cross-tenant write, which is the failure
    // #8 exists to catch.
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    $policyOfB = Tenancy::forTenant($b, fn (): CancellationPolicy => app(SaveCancellationPolicy::class)(
        new CancellationPolicy,
        ['name' => ['el' => 'Του Β', 'en' => 'B policy'], 'is_default' => true],
    ));

    Tenancy::forTenant($a, function (): void {
        app(SaveCancellationPolicy::class)(
            new CancellationPolicy,
            ['name' => ['el' => 'Του Α', 'en' => 'A policy'], 'is_default' => true],
        );
    });

    expect($policyOfB->refresh()->is_default)->toBeTrue();
})->group('fast', 'tenancy');

it('refuses two rungs at the same threshold', function (): void {
    // `cxl_tiers_policy_days_uq`. Evaluation takes the largest qualifying rung;
    // two at 7 days makes "which percentage" a matter of row order.
    inTenant(function (): void {
        $policy = CancellationPolicy::factory()->create();

        CancellationPolicyTier::factory()->create([
            'cancellation_policy_id' => $policy->getKey(),
            'days_before' => 7,
            'refund_percent' => 50,
        ]);

        CancellationPolicyTier::factory()->create([
            'cancellation_policy_id' => $policy->getKey(),
            'days_before' => 7,
            'refund_percent' => 25,
        ]);
    });
})->throws(QueryException::class)->group('fast');

it('allows the same threshold on two different policies', function (): void {
    // The unique index is per policy, not per tenant — a "Flexible" and a
    // "Strict" policy both perfectly reasonably have a 7-day rung.
    inTenant(function (): void {
        $flexible = CancellationPolicy::factory()->create(['name' => ['el' => 'Ευέλικτη', 'en' => 'Flexible']]);
        $strict = CancellationPolicy::factory()->create(['name' => ['el' => 'Αυστηρή', 'en' => 'Strict']]);

        CancellationPolicyTier::factory()->create(['cancellation_policy_id' => $flexible->getKey(), 'days_before' => 7, 'refund_percent' => 50]);
        CancellationPolicyTier::factory()->create(['cancellation_policy_id' => $strict->getKey(), 'days_before' => 7, 'refund_percent' => 10]);

        expect(CancellationPolicyTier::query()->count())->toBe(2);
    });
})->group('fast');

it('replaces the ladder rather than merging it', function (): void {
    // A rung the operator deleted in the repeater is a rung that should be
    // gone. Merging would leave a threshold nobody can see still deciding
    // refunds. Safe, because every booking holds its own frozen copy (CXL-1).
    inTenant(function (): void {
        $policy = app(SaveCancellationPolicy::class)(
            new CancellationPolicy,
            ['name' => ['el' => 'Κλίμακα', 'en' => 'Ladder']],
            [['days_before' => 15, 'refund_percent' => 100], ['days_before' => 7, 'refund_percent' => 50]],
        );

        expect($policy->tiers()->count())->toBe(2);

        app(SaveCancellationPolicy::class)($policy, [], [['days_before' => 30, 'refund_percent' => 100]]);

        expect($policy->refresh()->tiers()->pluck('days_before')->all())->toBe([30]);
    });
})->group('fast');

it('leaves the ladder alone when no tiers are passed', function (): void {
    // Null and empty mean different things: null is "I am not editing the
    // ladder", empty is "the ladder is now empty". An importer updating only a
    // name must not silently wipe the rungs.
    inTenant(function (): void {
        $policy = app(SaveCancellationPolicy::class)(
            new CancellationPolicy,
            ['name' => ['el' => 'Κλίμακα', 'en' => 'Ladder']],
            [['days_before' => 15, 'refund_percent' => 100]],
        );

        app(SaveCancellationPolicy::class)($policy, ['no_show_refund_percent' => 10], null);

        expect($policy->refresh()->tiers()->count())->toBe(1);

        app(SaveCancellationPolicy::class)($policy, [], []);

        expect($policy->refresh()->tiers()->count())->toBe(0);
    });
})->group('fast');

it('freezes into the §3.3 snapshot shape, tiers sorted descending', function (): void {
    // The snapshot is what M2 stores on the booking and what the refund
    // calculator reads for the rest of that booking's life, so its shape is a
    // contract rather than an implementation detail.
    inTenant(function (): void {
        $policy = CancellationPolicy::factory()
            ->withTiers([7 => 50, 15 => 100, 2 => 0])
            ->create();

        $snapshot = $policy->toSnapshotData()->toSnapshot();

        expect($snapshot)->toHaveKeys([
            'version', 'policy_id', 'name', 'summary', 'free_cancellation_hours',
            'weather_refund_percent', 'force_majeure_voucher_months',
            'no_show_refund_percent', 'tiers', 'captured_at',
        ])
            ->and($snapshot['version'])->toBe(CancellationPolicyData::VERSION)
            // Descending, per §3.3 — evaluation reads the first qualifying rung.
            ->and(array_column($snapshot['tiers'], 'days_before'))->toBe([15, 7, 2])
            ->and($snapshot['name'])->toBe(['el' => 'Ευέλικτη', 'en' => 'Flexible']);
    });
})->group('fast');

it('round-trips a stored snapshot without loading the policy', function (): void {
    // The read path M2 uses: JSON in, value object out, no database. A snapshot
    // must stay readable after the policy it came from is edited or deleted.
    inTenant(function (): void {
        $policy = CancellationPolicy::factory()->withTiers()->create();
        $snapshot = $policy->toSnapshotData()->toSnapshot();

        $policy->tiers()->delete();
        $policy->delete();

        $restored = CancellationPolicyData::fromSnapshot($snapshot);

        expect($restored->freeCancellationHours)->toBe(48)
            ->and(array_map(fn ($t): int => $t->daysBefore, $restored->tiers))->toBe([15, 7, 2]);
    });
})->group('fast');

it('refuses a policy saved with only one locale', function (): void {
    // #15's rule, on a field a guest reads before paying: a policy named in
    // Greek and blank in English is a booking page with a blank where the terms
    // should be.
    inTenant(function (): void {
        CancellationPolicy::factory()->create(['name' => ['el' => 'Μόνο ελληνικά']]);
    });
})->throws(MissingTranslationException::class)->group('fast');

it('lets an owner and a manager reach the policies page', function (Role $role): void {
    actingAs(OperatorUser::withRole($role))->get('/app/cancellation-policies')->assertSuccessful();
})->with([[Role::Owner], [Role::Manager]])->group('fast');

it('refuses crew the policies page', function (): void {
    // A refund ladder is money, gated on `ManagePricing` — crew are read-only
    // within a departure window and this is not part of standing on the quay.
    actingAs(OperatorUser::withRole(Role::Crew))->get('/app/cancellation-policies')->assertForbidden();
})->group('fast');

it('renders the policy labels in Greek and in English', function (): void {
    $user = OperatorUser::withRole(Role::Owner);

    // A row first, and this is not incidental: Filament renders the empty state
    // *instead of* the column headers when a table has no rows, so a label
    // assertion against an empty table never sees the label and passes for the
    // wrong reason. #16 hit exactly this.
    Tenancy::forTenant(Tenant::query()->findOrFail($user->tenant_id), function (): void {
        CancellationPolicy::factory()->create();
    });

    actingAs($user)->get('/app/cancellation-policies?lang=el')
        ->assertSuccessful()
        ->assertSee(__('pricing.cancellation.table.is_default', locale: 'el'))
        ->assertDontSee('pricing.cancellation.table.is_default');

    actingAs($user)->get('/app/cancellation-policies?lang=en')
        ->assertSuccessful()
        ->assertSee(__('pricing.cancellation.table.is_default', locale: 'en'));
})->group('fast', 'i18n');
