<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Filament\App\Resources\FaqResource\Pages\ListFaqs;
use App\Models\Faq;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
 * Spec TEN-8, SEC-3, and #103's own criterion.
 *
 * The FAQ is `ManageBranding` — owner and manager, never crew — for the reason
 * `FaqPolicy` gives: an entry changes what the business says about itself, not
 * what is for sale. The refusal is asserted against the policy and against the
 * URL, because Filament *allows* an action when no policy is registered and a
 * missing navigation item is not a permission.
 */

function faqTenantOf(User $user): Tenant
{
    return Tenant::query()->findOrFail($user->tenant_id);
}

/**
 * A mounted page with the tenant resolved, as the panel middleware would.
 *
 * A Livewire component test does not pass through the panel middleware, so
 * `ResolveTenant` never runs and every query on a tenant-owned model throws.
 */
function faqPageAs(User $user): Testable
{
    tenancy()->initialize(faqTenantOf($user));

    return Livewire::actingAs($user)->test(ListFaqs::class);
}

it('lets an owner and a manager reach the FAQ screen', function (Role $role): void {
    actingAs(OperatorUser::withRole($role))->get('/app/faqs')->assertSuccessful();
})->with([[Role::Owner], [Role::Manager]])->group('fast');

it('refuses crew the FAQ screen', function (): void {
    actingAs(OperatorUser::withRole(Role::Crew))->get('/app/faqs')->assertForbidden();
})->group('fast');

it('refuses crew every action on an entry, not only the page', function (): void {
    $crew = OperatorUser::withRole(Role::Crew);

    Tenancy::forTenant(faqTenantOf($crew), function () use ($crew): void {
        $faq = Faq::factory()->create();

        expect(Gate::forUser($crew)->allows('viewAny', Faq::class))->toBeFalse()
            ->and(Gate::forUser($crew)->allows('view', $faq))->toBeFalse()
            ->and(Gate::forUser($crew)->allows('create', Faq::class))->toBeFalse()
            ->and(Gate::forUser($crew)->allows('update', $faq))->toBeFalse()
            ->and(Gate::forUser($crew)->allows('delete', $faq))->toBeFalse()
            // Drag-and-drop ordering writes `sort_order`, so it is a write like
            // any other and crew are refused it too.
            ->and(Gate::forUser($crew)->allows('reorder', Faq::class))->toBeFalse();
    });
})->group('fast');

it('lets a manager reorder, which is what makes the handles appear', function (): void {
    $manager = OperatorUser::withRole(Role::Manager);

    Tenancy::forTenant(faqTenantOf($manager), function () use ($manager): void {
        // Filament asks the policy for `reorder` before it renders the handles,
        // and a policy with no such method denies — the feature would disappear
        // with nothing anywhere saying why.
        expect(Gate::forUser($manager)->allows('reorder', Faq::class))->toBeTrue();
    });
})->group('fast');

it('lists only the signed-in operator entries', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $other = Tenant::factory()->create();

    $mine = Tenancy::forTenant(
        faqTenantOf($owner),
        fn (): Faq => Faq::factory()->asking('Δική μου;', 'Mine?')->create(),
    );

    $theirs = Tenancy::forTenant(
        $other,
        fn (): Faq => Faq::factory()->asking('Δική τους;', 'Theirs?')->create(),
    );

    faqPageAs($owner)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
})->group('fast');

it('refuses a write when the tenant is read-only, and says why', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $tenant = faqTenantOf($owner);

    Tenancy::forTenant($tenant, function () use ($owner, $tenant): void {
        $faq = Faq::factory()->create();

        // TEN-9 through `TenantOwnedPolicy`: reading the screen still works —
        // an operator whose subscription lapsed can still see what their guests
        // are being shown — and every write comes back with the sentence that
        // explains it rather than a bare denial.
        $tenant->forceFill(['status' => TenantStatus::ReadOnly])->save();

        expect(Gate::forUser($owner)->allows('viewAny', Faq::class))->toBeTrue()
            ->and(Gate::forUser($owner)->allows('update', $faq))->toBeFalse()
            ->and(Gate::forUser($owner)->inspect('update', $faq)->message())
            ->toBe(__('errors.tenant_read_only'));
    });
})->group('fast');
