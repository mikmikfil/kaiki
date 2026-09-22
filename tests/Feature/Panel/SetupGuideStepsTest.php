<?php

declare(strict_types=1);

use App\Domain\Tenancy\Support\SetupChecklist;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\VesselType;
use App\Filament\Admin\Resources\TenantResource\Pages\EditTenant;
use App\Filament\App\Pages\Setup;
use App\Models\AuditLog;
use App\Models\CancellationPolicy;
use App\Models\Port;
use App\Models\Season;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vessel;
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

it('asks the questions in the order each answer is needed', function (): void {
    // The policy between VAT and the boat, because a trip cannot be published
    // without one. «Λιμάνια» before the boat and «Περίοδοι» after it (product
    // owner, 2026-09-22): a boat has a home port, a trip cannot be published
    // without a meeting point, and a period is met before the trip form offers
    // a price list to hang on one.
    expect(SetupChecklist::questions())->toBe([
        SetupChecklist::BUSINESS,
        SetupChecklist::BRANDING,
        SetupChecklist::VAT,
        SetupChecklist::CANCELLATION,
        SetupChecklist::PORT,
        SetupChecklist::VESSEL,
        SetupChecklist::SEASON,
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
            ->assertSet('step', SetupChecklist::PORT);

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
            ->and(SetupChecklist::progress())->toBe(['done' => 1, 'total' => 8]);
    });
})->group('fast');

it('asks for the logo and the two colours on the branding step, and saves them', function (): void {
    // Product owner, 2026-09-22: «στην εμφάνιση, μόνο logo και χρώματα· όχι
    // link προς εμφάνιση». The step used to hand off to the branding screen —
    // fifteen fields, and behind the gate it did not even open.
    $owner = guideOwner();
    actingAs($owner);

    Tenancy::forTenant($owner->tenant, function () use ($owner): void {
        Livewire::test(Setup::class)
            ->set('step', SetupChecklist::BRANDING)
            // No hand-off button on this step any more.
            ->assertDontSee(__('setup.steps.branding.action'))
            ->set('data.color_primary', '#0B4F4A')
            ->set('data.color_secondary', '#063733')
            ->call('continue')
            ->assertSet('step', SetupChecklist::VAT);

        $profile = $owner->tenant->refresh()->brandProfile()->first();

        expect($profile?->color_primary)->toBe('#0B4F4A')
            ->and($profile?->color_secondary)->toBe('#063733')
            ->and(SetupChecklist::state()[SetupChecklist::BRANDING])->toBeTrue();
    });
})->group('fast');

it('lets the guide reach the screens it hands off to', function (): void {
    // The gate holds every other panel page back, and three of the seven steps
    // are answered elsewhere: the boat, the periods and the first trip.
    $owner = guideOwner();

    foreach (Setup::handOffUrls() as $url) {
        actingAs($owner)->get($url)->assertSuccessful();
    }

    // …and nothing beyond them.
    actingAs($owner)->get('/app/bookings')->assertRedirect(Setup::getUrl());
})->group('fast');

it('makes the port, the boat and the period without leaving the guide', function (): void {
    // Product owner, 2026-09-22: «ανοίγει το προσθήκη σκάφους με όλη την
    // πλατφόρμα μετά». The guide runs without navigation on purpose, so three
    // steps that dropped the operator into the whole panel are asked here
    // instead — each of them is two or three fields.
    $owner = guideOwner();
    actingAs($owner);

    Tenancy::forTenant($owner->tenant, function (): void {
        Livewire::test(Setup::class)
            ->set('step', SetupChecklist::PORT)
            ->set('data.port_name', 'Παλιό Λιμάνι Χανίων')
            ->set('data.port_address', 'Ακτή Τομπάζη')
            ->call('continue')
            ->assertSet('step', SetupChecklist::VESSEL)
            ->set('data.vessel_name', 'Αύρα')
            ->set('data.vessel_type', VesselType::TraditionalKaiki->value)
            ->set('data.vessel_capacity', 30)
            ->call('continue')
            ->assertSet('step', SetupChecklist::SEASON)
            ->set('data.season_name', 'Θερινή')
            ->set('data.season_starts_on', '2027-06-01')
            ->set('data.season_ends_on', '2027-09-15')
            ->call('continue');

        $port = Port::query()->sole();
        $vessel = Vessel::query()->sole();
        $season = Season::query()->sole();

        expect($port->getTranslation('name', 'el'))->toBe('Παλιό Λιμάνι Χανίων')
            ->and($vessel->name)->toBe('Αύρα')
            ->and($vessel->capacity_max)->toBe(30)
            // The boat sails from the port the step before made.
            ->and($vessel->home_port_id)->toBe($port->getKey())
            ->and($season->getTranslation('name', 'el'))->toBe('Θερινή')
            ->and($season->dateRanges()->count())->toBe(1);

        $state = SetupChecklist::state();

        expect($state[SetupChecklist::PORT])->toBeTrue()
            ->and($state[SetupChecklist::VESSEL])->toBeTrue()
            ->and($state[SetupChecklist::SEASON])->toBeTrue();
    });
})->group('fast');

it('leaves only the trip behind a door, and the door opens', function (): void {
    // The trip keeps its hand-off: it has a four-step guide of its own, and a
    // guide inside a guide is worse than a door. The gate lets that one
    // through and nothing else.
    $owner = guideOwner();

    expect(Setup::handOffUrls())->toHaveCount(1);

    foreach (Setup::handOffUrls() as $url) {
        actingAs($owner)->get($url)->assertSuccessful();
    }
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
