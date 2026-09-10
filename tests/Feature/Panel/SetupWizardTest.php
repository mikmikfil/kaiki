<?php

declare(strict_types=1);

use App\Domain\Tenancy\Support\SetupChecklist;
use App\Enums\Role;
use App\Filament\App\Pages\PaymentSettings;
use App\Filament\App\Pages\Setup;
use App\Filament\App\Resources\ProductResource;
use App\Filament\App\Widgets\SetupProgress;
use App\Http\Middleware\OfferSetupOnce;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VatRate;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| #51 — the operator setup guide (SAA-9, SAA-10)
|--------------------------------------------------------------------------
|
| The acceptance criteria of the issue, one test each, plus the two things the
| design turns on: that every step is answered from the data rather than from a
| stored pointer, and that nothing here can block an operator out of the panel.
|
| `Tenancy::forTenant()` around every assertion that touches the checklist —
| every step is a tenant-scoped query, and outside a tenant they are all false
| by design.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-10 09:00:00');
});

/** A rate an operator may actually pick. */
function selectableRate(int $bp = 1300): VatRate
{
    return VatRate::query()->create([
        'code' => 'RED' . $bp,
        'rate_bp' => $bp,
        'vat_category' => '2',
        'description' => ['el' => 'Μειωμένος', 'en' => 'Reduced'],
        'valid_from' => '2026-01-01',
        'is_selectable' => true,
    ]);
}

/**
 * An account as it is the minute it is created, rather than as the factory
 * imagines it.
 *
 * `TenantFactory` fills in a legal name and an ΑΦΜ, because most tests want an
 * operator who is already trading. Every test about the *first* afternoon has to
 * undo that, or it asserts against a checklist that starts a third done.
 */
function unconfiguredOperator(): User
{
    return OperatorUser::withRole(Role::Owner, Tenant::factory()->unconfigured()->create());
}

/* -----------------------------------------------------------------
 | The checklist is derived, not stored
 ----------------------------------------------------------------- */

it('answers each step from the data rather than from a stored pointer', function (): void {
    $owner = unconfiguredOperator();

    Tenancy::forTenant($owner->tenant, function () use ($owner): void {
        expect(SetupChecklist::state()[SetupChecklist::VESSEL])->toBeFalse();

        // Added from the Σκάφη screen, without the wizard being told anything.
        Vessel::factory()->create();

        expect(SetupChecklist::state()[SetupChecklist::VESSEL])->toBeTrue()
            ->and($owner->tenant->refresh()->onboarding_skipped_steps)->toBeNull();
    });
});

it('counts the business step done only once both halves of an invoice are there', function (): void {
    $owner = unconfiguredOperator();

    Tenancy::forTenant($owner->tenant, function () use ($owner): void {
        $owner->tenant->forceFill(['legal_name' => 'Αιγαίο ΑΕ', 'vat_number' => null])->save();

        expect(SetupChecklist::state()[SetupChecklist::BUSINESS])->toBeFalse();

        $owner->tenant->forceFill(['vat_number' => '123456789'])->save();

        expect(SetupChecklist::state()[SetupChecklist::BUSINESS])->toBeTrue();
    });
});

it('does not count an empty string as an answer', function (): void {
    $owner = unconfiguredOperator();

    Tenancy::forTenant($owner->tenant, function () use ($owner): void {
        // What a form posts for a field somebody clicked into and left.
        $owner->tenant->forceFill(['legal_name' => '  ', 'vat_number' => ''])->save();

        expect(SetupChecklist::state()[SetupChecklist::BUSINESS])->toBeFalse();
    });
});

/* -----------------------------------------------------------------
 | Acceptance: every step can be skipped, and skipping is not completing
 ----------------------------------------------------------------- */

it('lets a step be skipped and passes over it when resuming', function (): void {
    $owner = unconfiguredOperator();

    actingAs($owner);

    Tenancy::forTenant($owner->tenant, function (): void {
        expect(SetupChecklist::next())->toBe(SetupChecklist::BUSINESS);

        Livewire::test(Setup::class)->call('skip', SetupChecklist::BUSINESS);

        expect(SetupChecklist::skipped())->toBe([SetupChecklist::BUSINESS])
            // Passed over, not returned to. That is the difference between
            // resuming and nagging.
            ->and(SetupChecklist::next())->toBe(SetupChecklist::BRANDING)
            // And it is still not done — the checklist keeps showing it.
            ->and(SetupChecklist::state()[SetupChecklist::BUSINESS])->toBeFalse();
    });
});

it('puts a skipped step back when the operator asks', function (): void {
    $owner = unconfiguredOperator();

    actingAs($owner);

    Tenancy::forTenant($owner->tenant, function (): void {
        Livewire::test(Setup::class)->call('skip', SetupChecklist::VAT);
        expect(SetupChecklist::skipped())->toBe([SetupChecklist::VAT]);

        Livewire::test(Setup::class)->call('unskip', SetupChecklist::VAT);
        expect(SetupChecklist::skipped())->toBe([]);
    });
});

it('refuses to record a skip for a step that does not exist', function (): void {
    $owner = unconfiguredOperator();

    actingAs($owner);

    Tenancy::forTenant($owner->tenant, function () use ($owner): void {
        Livewire::test(Setup::class)->call('skip', 'not_a_step');

        expect($owner->tenant->refresh()->onboarding_skipped_steps)->toBeNull();
    });
});

it('counts a skipped step as settled so the progress figure is not stuck', function (): void {
    $owner = unconfiguredOperator();

    actingAs($owner);

    Tenancy::forTenant($owner->tenant, function (): void {
        expect(SetupChecklist::progress())->toBe(['done' => 0, 'total' => 6]);

        Livewire::test(Setup::class)->call('skip', SetupChecklist::VAT);

        expect(SetupChecklist::progress())->toBe(['done' => 1, 'total' => 6]);
    });
});

/* -----------------------------------------------------------------
 | Acceptance: the VAT step, and what it pre-fills
 ----------------------------------------------------------------- */

it('sets the tenant default from the VAT step and pre-fills a new product with it', function (): void {
    $owner = unconfiguredOperator();

    actingAs($owner);

    Tenancy::forTenant($owner->tenant, function () use ($owner): void {
        $rate = selectableRate();

        Livewire::test(Setup::class)
            ->set('data.default_vat_rate_id', $rate->getKey())
            ->call('finish');

        expect($owner->tenant->refresh()->default_vat_rate_id)->toBe($rate->getKey())
            ->and(SetupChecklist::state()[SetupChecklist::VAT])->toBeTrue()
            // The whole point of the column: the product form starts there.
            ->and(ProductResource::defaultVatRateId())->toBe($rate->getKey());
    });
});

it('ignores a rate the operator is not allowed to pick', function (): void {
    $owner = unconfiguredOperator();

    actingAs($owner);

    Tenancy::forTenant($owner->tenant, function () use ($owner): void {
        $withdrawn = selectableRate(2400);
        $withdrawn->forceFill(['is_selectable' => false])->save();

        // A Livewire payload is something a person can craft.
        Livewire::test(Setup::class)
            ->set('data.default_vat_rate_id', $withdrawn->getKey())
            ->call('finish');

        expect($owner->tenant->refresh()->default_vat_rate_id)->toBeNull();
    });
});

it('does not pre-fill a product from a rate withdrawn after it was chosen', function (): void {
    $owner = unconfiguredOperator();

    Tenancy::forTenant($owner->tenant, function () use ($owner): void {
        $rate = selectableRate();
        $owner->tenant->forceFill(['default_vat_rate_id' => $rate->getKey()])->save();

        expect(ProductResource::defaultVatRateId())->toBe($rate->getKey());

        // #47's way of retiring a rate. The stored id still resolves as a row,
        // and the select can no longer render it — an id returned here would be
        // a field that looks empty and submits a value.
        $rate->forceFill(['is_selectable' => false])->save();

        expect(ProductResource::defaultVatRateId())->toBeNull();
    });
});

/* -----------------------------------------------------------------
 | Acceptance: nothing is broken for an operator who never finishes
 ----------------------------------------------------------------- */

it('leaves the panel entirely usable for an operator who never opens the guide', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant($owner->tenant, function (): void {
        // CAT-11b and §2.3: `vat_rate_id` is nullable by design, and a product
        // without one is a product, not a broken record.
        $product = Product::factory()->create(['vat_rate_id' => null]);

        expect($product->exists)->toBeTrue()
            ->and($product->vat_rate_id)->toBeNull();
    });
});

it('stops showing the checklist only when the wizard is finished', function (): void {
    $owner = unconfiguredOperator();

    actingAs($owner);

    Tenancy::forTenant($owner->tenant, function () use ($owner): void {
        expect(SetupChecklist::applies())->toBeTrue();

        Livewire::test(Setup::class)->call('finish');

        expect($owner->tenant->refresh()->onboarding_completed_at)->not->toBeNull()
            ->and(SetupChecklist::applies())->toBeFalse()
            ->and(SetupProgress::canView())->toBeFalse();
    });
});

it('keeps the checklist while a step is merely skipped', function (): void {
    $owner = unconfiguredOperator();

    actingAs($owner);

    Tenancy::forTenant($owner->tenant, function (): void {
        Livewire::test(Setup::class)->call('skip', SetupChecklist::VAT);

        // Waiting for an accountant is not the same as being finished, and the
        // checklist is exactly where that outstanding item should stay.
        expect(SetupChecklist::applies())->toBeTrue()
            ->and(SetupProgress::canView())->toBeTrue();
    });
});

/* -----------------------------------------------------------------
 | Acceptance: crew never see the wizard (TEN-8)
 ----------------------------------------------------------------- */

it('is closed to crew and to managers, and open to the owner', function (): void {
    $tenant = Tenant::factory()->unconfigured()->create();

    $owner = OperatorUser::withRole(Role::Owner, $tenant);
    $manager = OperatorUser::withRole(Role::Manager, $tenant);
    $crew = OperatorUser::withRole(Role::Crew, $tenant);

    Tenancy::forTenant($tenant, function () use ($owner, $manager, $crew): void {
        actingAs($crew);
        expect(Setup::canAccess())->toBeFalse()
            ->and(SetupProgress::canView())->toBeFalse();

        actingAs($manager);
        expect(Setup::canAccess())->toBeFalse();

        actingAs($owner);
        expect(Setup::canAccess())->toBeTrue()
            ->and(SetupProgress::canView())->toBeTrue();
    });
});

it('refuses a write from somebody who cannot open the page', function (): void {
    $tenant = Tenant::factory()->unconfigured()->create();

    OperatorUser::withRole(Role::Owner, $tenant);
    $crew = OperatorUser::withRole(Role::Crew, $tenant);

    actingAs($crew);

    Tenancy::forTenant($tenant, function () use ($tenant): void {
        // A Livewire action is a POST somebody can craft without ever loading
        // the page, so the render gate is not the only one that has to hold.
        try {
            Livewire::test(Setup::class)->call('skip', SetupChecklist::VAT);
        } catch (Throwable) {
            // Whether Livewire refuses to mount the page or the write aborts,
            // the assertion that matters is the same one: nothing was written.
        }

        expect($tenant->refresh()->onboarding_skipped_steps)->toBeNull();
    });
});

/* -----------------------------------------------------------------
 | The guide is offered once per sign-in, and never nags
 ----------------------------------------------------------------- */

it('offers the guide once and then leaves the operator alone', function (): void {
    $owner = unconfiguredOperator();

    actingAs($owner)
        ->get('/app')
        ->assertRedirect(Setup::getUrl());

    // The second request is the one that matters. SAA-10: not on every page
    // load.
    actingAs($owner)
        ->withSession([OfferSetupOnce::OFFERED => true])
        ->get('/app')
        ->assertOk();
});

it('never offers the guide to somebody who cannot open it', function (): void {
    $crew = OperatorUser::withRole(Role::Crew, Tenant::factory()->unconfigured()->create());

    actingAs($crew)
        ->get('/app')
        ->assertOk();
});

it('stops offering the guide once it has been finished', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    $owner->tenant->forceFill(['onboarding_completed_at' => Carbon::now()])->save();

    actingAs($owner)
        ->get('/app')
        ->assertOk();
});

/* -----------------------------------------------------------------
 | It leaves the navigation for good once it is finished
 ----------------------------------------------------------------- */

it('is in the navigation while there is setting up left to do', function (): void {
    $owner = unconfiguredOperator();

    actingAs($owner);

    Tenancy::forTenant($owner->tenant, function (): void {
        expect(Setup::shouldRegisterNavigation())->toBeTrue()
            // Ungrouped, which is what puts it above the three groups rather
            // than inside the collapsed one nobody opens.
            ->and(Setup::getNavigationGroup())->toBeNull();
    });
});

it('leaves the navigation entirely once the guide is finished', function (): void {
    $owner = unconfiguredOperator();

    actingAs($owner);

    Tenancy::forTenant($owner->tenant, function (): void {
        Livewire::test(Setup::class)->call('finish');

        expect(Setup::shouldRegisterNavigation())->toBeFalse()
            ->and(Setup::getNavigationBadge())->toBeNull()
            ->and(SetupProgress::canView())->toBeFalse();
    });
});

it('keeps the page itself reachable, so the business details are not stranded', function (): void {
    $owner = unconfiguredOperator();

    actingAs($owner);

    Tenancy::forTenant($owner->tenant, function (): void {
        Livewire::test(Setup::class)->call('finish');

        // Out of the menu, not out of the product. The guide is the only screen
        // that asks for the legal name and the ΑΦΜ.
        expect(Setup::canAccess())->toBeTrue();
    });

    actingAs($owner)->get(Setup::getUrl())->assertOk();
});

it('gives the VAT default a permanent home on the payments screen', function (): void {
    $owner = unconfiguredOperator();

    actingAs($owner);

    Tenancy::forTenant($owner->tenant, function () use ($owner): void {
        $rate = selectableRate();

        // Set through the guide, then changed from Ρυθμίσεις → Πληρωμές once
        // the guide is gone — which is the whole reason it lives there too.
        Livewire::test(PaymentSettings::class)
            ->set('data.default_vat_rate_id', $rate->getKey())
            ->call('save');

        expect($owner->tenant->refresh()->default_vat_rate_id)->toBe($rate->getKey())
            ->and(ProductResource::defaultVatRateId())->toBe($rate->getKey());
    });
});

it('will not take a withdrawn rate from the payments screen either', function (): void {
    $owner = unconfiguredOperator();

    actingAs($owner);

    Tenancy::forTenant($owner->tenant, function () use ($owner): void {
        $withdrawn = selectableRate(2400);
        $withdrawn->forceFill(['is_selectable' => false])->save();

        Livewire::test(PaymentSettings::class)
            ->set('data.default_vat_rate_id', $withdrawn->getKey())
            ->call('save');

        expect($owner->tenant->refresh()->default_vat_rate_id)->toBeNull();
    });
});
