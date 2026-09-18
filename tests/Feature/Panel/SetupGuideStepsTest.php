<?php

declare(strict_types=1);

use App\Domain\Tenancy\Support\SetupChecklist;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Filament\Admin\Resources\TenantResource\Pages\EditTenant;
use App\Filament\App\Pages\Setup;
use App\Models\AuditLog;
use App\Models\CancellationPolicy;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| The setup guide, one step at a time (product owner, 2026-09-17)
|--------------------------------------------------------------------------
|
| Direction B of the onboarding mockups: a step list beside one step, the
| cancellation policy as its own step with three ready ladders, and a switch on
| /admin that turns the whole guide off for an operator.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-10 09:00:00');
});

function guideOwner(): User
{
    return OperatorUser::withRole(Role::Owner, Tenant::factory()->unconfigured()->create());
}

it('asks about the cancellation policy between VAT and the first boat', function (): void {
    expect(SetupChecklist::questions())->toBe([
        SetupChecklist::BUSINESS,
        SetupChecklist::BRANDING,
        SetupChecklist::VAT,
        SetupChecklist::CANCELLATION,
        SetupChecklist::VESSEL,
        SetupChecklist::PRODUCT,
    ]);
})->group('fast');

it('saves the chosen ladder as the default policy and moves on', function (): void {
    $owner = guideOwner();
    actingAs($owner);

    Tenancy::forTenant($owner->tenant, function (): void {
        Livewire::test(Setup::class)
            ->set('step', SetupChecklist::CANCELLATION)
            ->call('choosePreset', 'strict')
            ->call('continue')
            ->assertSet('step', SetupChecklist::VESSEL);

        $policy = CancellationPolicy::query()->with('tiers')->sole();

        expect($policy->is_default)->toBeTrue()
            ->and($policy->getTranslation('name', 'el'))->toBe('Αυστηρή')
            ->and($policy->tiers->map(fn ($tier): array => [$tier->days_before, $tier->refund_percent])->all())->toBe([[14, 50]])
            ->and(SetupChecklist::state()[SetupChecklist::CANCELLATION])->toBeTrue();
    });
})->group('fast');

it('never adds a second policy for an operator who already has one', function (): void {
    $owner = guideOwner();
    actingAs($owner);

    Tenancy::forTenant($owner->tenant, function (): void {
        CancellationPolicy::factory()->create();

        Livewire::test(Setup::class)
            ->set('step', SetupChecklist::CANCELLATION)
            ->call('continue');

        expect(CancellationPolicy::query()->count())->toBe(1);
    });
})->group('fast');

it('sets a step aside with «Αργότερα» and goes forward to the next open one', function (): void {
    $owner = guideOwner();
    actingAs($owner);

    Tenancy::forTenant($owner->tenant, function (): void {
        Livewire::test(Setup::class)
            ->set('step', SetupChecklist::BUSINESS)
            ->call('later')
            ->assertSet('step', SetupChecklist::BRANDING)
            ->call('back')
            ->assertSet('step', SetupChecklist::BUSINESS);

        expect(SetupChecklist::skipped())->toBe([SetupChecklist::BUSINESS])
            ->and(SetupChecklist::progress())->toBe(['done' => 1, 'total' => 6]);
    });
})->group('fast');

it('saves the business details on «Συνέχεια»', function (): void {
    $owner = guideOwner();
    actingAs($owner);

    Tenancy::forTenant($owner->tenant, function () use ($owner): void {
        Livewire::test(Setup::class)
            ->set('step', SetupChecklist::BUSINESS)
            ->set('data.legal_name', 'Webflow Ι.Κ.Ε.')
            ->set('data.vat_number', '801234567')
            ->call('continue');

        expect($owner->tenant->refresh()->legal_name)->toBe('Webflow Ι.Κ.Ε.')
            ->and(SetupChecklist::state()[SetupChecklist::BUSINESS])->toBeTrue();
    });
})->group('fast');

it('offers no guide at all to an operator the platform switched it off for', function (): void {
    $owner = guideOwner();
    $owner->tenant->forceFill(['setup_guide_enabled' => false])->save();

    actingAs($owner)->get('/app')->assertOk();

    Tenancy::forTenant($owner->tenant->refresh(), function (): void {
        expect(SetupChecklist::applies())->toBeFalse()
            ->and(Setup::shouldRegisterNavigation())->toBeFalse();
    });
})->group('fast');

it('keeps the guide for an operator who existed before the switch', function (): void {
    $tenant = Tenant::factory()->unconfigured()->create();
    $tenant->forceFill(['setup_guide_enabled' => null])->save();

    expect($tenant->refresh()->usesSetupGuide())->toBeTrue();
})->group('fast');

it('lets the platform switch the guide off, with a reason, in the operator\'s trail', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->superAdmin()->create();

    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::actingAs($admin)
        ->test(EditTenant::class, ['record' => $tenant->getRouteKey()])
        ->assertFormSet(['setup_guide_enabled' => true])
        ->fillForm(['setup_guide_enabled' => false])
        ->callAction('save', ['auditReason' => 'Στήσαμε μαζί τον λογαριασμό στο τηλέφωνο.'])
        ->assertHasNoErrors();

    expect($tenant->refresh()->usesSetupGuide())->toBeFalse();

    Tenancy::forTenant($tenant, function (): void {
        $entry = AuditLog::query()->where('action', AuditAction::TenantUpdated->value)->sole();

        expect($entry->context)->toHaveKey('setup_guide_enabled_to', false);
    });
})->group('fast');

it('restarts the guide from /admin without losing what was filled in', function (): void {
    $tenant = Tenant::factory()->create();
    $tenant->forceFill([
        'legal_name' => 'Webflow Ι.Κ.Ε.',
        'onboarding_completed_at' => Carbon::now(),
        'onboarding_skipped_steps' => [SetupChecklist::VAT],
    ])->save();
    $admin = User::factory()->superAdmin()->create();

    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::actingAs($admin)
        ->test(EditTenant::class, ['record' => $tenant->getRouteKey()])
        ->callAction('resetSetupGuide', ['reason' => 'Ο διοργανωτής θέλει να τον ξαναδεί.'])
        ->assertHasNoActionErrors();

    $tenant->refresh();

    expect($tenant->onboarding_completed_at)->toBeNull()
        ->and($tenant->onboarding_skipped_steps)->toBeNull()
        ->and($tenant->legal_name)->toBe('Webflow Ι.Κ.Ε.');
})->group('fast');
