<?php

declare(strict_types=1);

use App\Domain\Tenancy\Support\SetupChecklist;
use App\Enums\AuditAction;
use App\Enums\CrewSpecialty;
use App\Enums\HomeBlockType;
use App\Enums\HostedSiteMode;
use App\Enums\Role;
use App\Filament\Admin\Resources\TenantResource\Pages\EditTenant;
use App\Filament\App\Pages\Setup;
use App\Models\AuditLog;
use App\Models\CancellationPolicy;
use App\Models\HomePageBlock;
use App\Models\PolicyTemplate;
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

it('asks the questions in the order each answer is needed', function (): void {
    // Four questions about the **account**, and nothing about the catalogue
    // (product owner, 2026-09-22: «λέω να φύγουν λιμάνια, πρώτο σκάφος και
    // εκδρομή και περίοδοι»). Each of those has a real screen of its own, and
    // asking for them here was either a hand-off out of the guide or a
    // shortened copy of a form that already exists.
    // The home page step (2026-09-23) is conditional: an operator who gets a
    // home page from us is asked to build it, last. The logo and the colours
    // left on 2026-09-25 — the platform sets them from /admin.
    expect(SetupChecklist::questions())->toBe([
        SetupChecklist::BUSINESS,
        SetupChecklist::VAT,
        SetupChecklist::CANCELLATION,
        SetupChecklist::HOME_PAGE,
    ]);

    // Still owed, still reported — just not questions here.
    expect(SetupChecklist::catalogueSteps())->toBe([
        SetupChecklist::PORT,
        SetupChecklist::VESSEL,
        SetupChecklist::SEASON,
        // Second to last (Mike, 2026-09-24).
        SetupChecklist::CREW,
        SetupChecklist::PRODUCT,
    ])
        ->and(array_keys(SetupChecklist::state()))->toContain(SetupChecklist::VESSEL);
})->group('fast');

it('saves the chosen ladder as the default policy and moves on', function (): void {
    $owner = guideOwner();
    actingAs($owner);

    // The ladders on offer are platform rows since 2026-09-23, so the test
    // states the one it chooses rather than leaning on a `const`. These are the
    // shipped «Αυστηρή» numbers, and the copy written into the operator's own
    // account is asserted against them below.
    PolicyTemplate::factory()->create([
        'code' => 'strict',
        'name' => ['el' => 'Αυστηρή', 'en' => 'Strict'],
        'free_cancellation_hours' => null,
        'tiers' => [['days_before' => 14, 'refund_percent' => 50]],
    ]);

    Tenancy::forTenant($owner->tenant, function (): void {
        Livewire::test(Setup::class)
            ->set('step', SetupChecklist::CANCELLATION)
            ->call('choosePreset', 'strict')
            ->call('continue')
            // The home page step sits between this and the close since
            // 2026-09-23, for an operator who gets a home page from us — which
            // the factory's default site mode does.
            ->assertSet('step', SetupChecklist::HOME_PAGE);

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
            ->assertSet('step', SetupChecklist::VAT)
            ->call('back')
            ->assertSet('step', SetupChecklist::BUSINESS);

        // Four questions: the home page step joined them (2026-09-23) and the
        // branding step left (2026-09-25), so the figure is counted rather
        // than typed here.
        expect(SetupChecklist::skipped())->toBe([SetupChecklist::BUSINESS])
            ->and(SetupChecklist::progress())->toBe(['done' => 1, 'total' => count(SetupChecklist::questions())])
            ->and(count(SetupChecklist::questions()))->toBe(4);
    });
})->group('fast');

it('has no branding step any more, and ignores one asked for by URL', function (): void {
    // Mike, 2026-09-25: «την αρχικοποίηση θέλω να την κάνω από το admin». The
    // logo and the colours are set on Create / Edit Merchant; the operator
    // still changes them on «Εμφάνιση».
    $owner = guideOwner();
    actingAs($owner);

    Tenancy::forTenant($owner->tenant, function (): void {
        Livewire::test(Setup::class)
            ->set('step', 'branding')
            ->assertDontSee(__('branding.form.color_primary.label'))
            ->call('continue')
            ->assertSet('step', SetupChecklist::VAT);

        expect(SetupChecklist::state())->not->toHaveKey('branding');
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

it('asks for a home page only from the operators who get one', function (): void {
    // Mike, 2026-09-23: *«στο first time config, σε αυτούς που έχουν full
    // website να υπάρχει και για εκεί βήμα τελευταίο να σε στέλνει να φτιάχνεις
    // την σελίδα»*.
    //
    // The conditional half is the point. A `bookings_only` account has no
    // marketing home page from us — their pages are one per trip — so the step
    // would hand them to a screen that governs nothing they have, and the
    // dashboard would report a step they were never offered as outstanding.
    $owner = guideOwner();
    actingAs($owner);

    Tenancy::forTenant($owner->tenant, function (): void {
        expect(SetupChecklist::questions())->toContain(SetupChecklist::HOME_PAGE)
            // Not done until there is something on the page: a home page an
            // operator opened and left empty is the one the guide most needs to
            // keep asking about.
            ->and(SetupChecklist::state()[SetupChecklist::HOME_PAGE])->toBeFalse();

        HomePageBlock::factory()->ofType(HomeBlockType::Faq)->create();

        expect(SetupChecklist::state()[SetupChecklist::HOME_PAGE])->toBeTrue();

    });

    $bookingsOnly = $owner->tenant;
    $bookingsOnly->forceFill(['hosted_site_mode' => HostedSiteMode::BookingsOnly])->save();

    Tenancy::forTenant($bookingsOnly, function (): void {
        expect(SetupChecklist::questions())->not->toContain(SetupChecklist::HOME_PAGE)
            // …and reported as settled rather than outstanding, so the
            // dashboard does not chase them for it.
            ->and(SetupChecklist::state()[SetupChecklist::HOME_PAGE])->toBeTrue();
    });
})->group('fast');

it('ticks «Πλήρωμα» once there is a captain or a deckhand, and not for anyone else', function (): void {
    // Mike, 2026-09-24: crew in the dashboard's list, second to last, not as
    // a question in the guide.
    $owner = guideOwner();
    actingAs($owner);

    Tenancy::forTenant($owner->tenant, function () use ($owner): void {
        expect(SetupChecklist::questions())->not->toContain(SetupChecklist::CREW)
            ->and(SetupChecklist::state()[SetupChecklist::CREW])->toBeFalse();

        // The owner alone, and somebody whose specialty is «Άλλο», are not crew.
        User::factory()->create(['tenant_id' => $owner->tenant_id, 'specialty' => CrewSpecialty::Other]);
        expect(SetupChecklist::state()[SetupChecklist::CREW])->toBeFalse();

        // Another operator's captain does not count here.
        User::factory()->create(['tenant_id' => Tenant::factory()->create()->getKey(), 'specialty' => CrewSpecialty::Captain]);
        expect(SetupChecklist::state()[SetupChecklist::CREW])->toBeFalse();

        User::factory()->create(['tenant_id' => $owner->tenant_id, 'email' => null, 'specialty' => CrewSpecialty::Deckhand]);
        expect(SetupChecklist::state()[SetupChecklist::CREW])->toBeTrue();
    });
})->group('fast');
