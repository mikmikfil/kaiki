<?php

declare(strict_types=1);

use App\Domain\Operations\Support\FirstSteps as Steps;
use App\Enums\BookingMode;
use App\Enums\HomeBlockType;
use App\Enums\HostedSiteMode;
use App\Enums\ProductCategory;
use App\Enums\ProductStatus;
use App\Enums\Role;
use App\Enums\VesselStatus;
use App\Enums\VesselType;
use App\Filament\App\Pages\Dashboard;
use App\Filament\App\Pages\HomePage;
use App\Filament\App\Resources\PortResource;
use App\Filament\App\Resources\PortResource\Pages\CreatePort;
use App\Filament\App\Resources\ProductResource;
use App\Filament\App\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\App\Resources\ProductResource\Pages\EditProduct;
use App\Filament\App\Resources\SeasonResource\Pages\CreateSeason;
use App\Filament\App\Resources\VesselResource\Pages\CreateVessel;
use App\Filament\App\Widgets\FirstSteps;
use App\Models\AgeBand;
use App\Models\CancellationPolicy;
use App\Models\HomePageBlock;
use App\Models\Port;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\RatePlanPrice;
use App\Models\Season;
use App\Models\User;
use App\Models\Vessel;
use App\Support\Tenancy;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| Back to the first steps once a step is done (Mike, 2026-09-25)
|--------------------------------------------------------------------------
|
| «Όταν κάνεις κάτι από τη λίστα, π.χ. βάζεις τα λιμάνια, να σε ξαναπηγαίνει
| στα επόμενα βήματα.» The checklist's links carry `?from=first-steps`; the
| page they open sends the operator back to the dashboard when the step is
| done. The same page reached any other way behaves as it always has.
|
*/

/**
 * A page as the operator, tenant resolved, opened with `$query` on its URL.
 *
 * @param  array<string, mixed>  $params
 * @param  array<string, string>  $query
 */
function firstStepsPage(User $user, string $page, array $params = [], array $query = []): Testable
{
    tenancy()->initialize($user->tenant);

    return Livewire::withQueryParams($query)->actingAs($user)->test($page, $params);
}

/** @return array<string, string> */
function fromChecklist(): array
{
    return ['from' => FirstSteps::MARKER];
}

it('marks the links that finish a step, and not the two lists', function (): void {
    $user = OperatorUser::withRole(Role::Owner);
    tenancy()->initialize($user->tenant);

    $links = (new FirstSteps)->getLinks();

    foreach ([Steps::PORT, Steps::VESSEL, Steps::SEASON, Steps::PRODUCT, Steps::HOME_PAGE] as $step) {
        expect($links[$step])->toEndWith('?from=' . FirstSteps::MARKER);
    }

    expect($links[Steps::PUBLISHED])->not->toContain('from=');

    // And on the screen: the next step's button and the optional one's.
    Livewire::actingAs($user)->test(FirstSteps::class)
        ->assertSee(PortResource::getUrl('create') . '?from=' . FirstSteps::MARKER, escape: false)
        ->assertSee(HomePage::getUrl() . '?from=' . FirstSteps::MARKER, escape: false);
})->group('fast');

it('returns to the dashboard after a port made from the checklist, and to the port otherwise', function (): void {
    $user = OperatorUser::withRole(Role::Owner);
    $form = [
        'name' => ['el' => 'Μαρίνα Ζέας', 'en' => 'Zea Marina'],
        'address' => 'Ακτή Θεμιστοκλέους, Πειραιάς',
        'is_active' => true,
        'sort_order' => 0,
    ];

    firstStepsPage($user, CreatePort::class, query: fromChecklist())
        ->fillForm($form)
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(Dashboard::getUrl());

    $page = firstStepsPage($user, CreatePort::class)
        ->fillForm(['name' => ['el' => 'Λιμάνι Πάργας', 'en' => 'Parga port']] + $form)
        ->call('create')
        ->assertHasNoFormErrors();

    $port = Tenancy::forTenant($user->tenant, fn (): Port => Port::query()->latest('id')->firstOrFail());

    $page->assertRedirect(PortResource::getUrl('edit', ['record' => $port]));
})->group('fast');

it('returns to the dashboard after a boat made from the checklist', function (): void {
    $user = OperatorUser::withRole(Role::Owner);
    $form = [
        'name' => 'Οδυσσέας',
        'type' => VesselType::TraditionalKaiki->value,
        'status' => VesselStatus::Active->value,
        'capacity_max' => 42,
        'crew_count' => 3,
        'description' => ['el' => 'Παραδοσιακό καΐκι', 'en' => 'A traditional kaiki'],
    ];

    firstStepsPage($user, CreateVessel::class, query: fromChecklist())
        ->fillForm($form)
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(Dashboard::getUrl());

    // Without the marker, not to the dashboard. Another operator: the plan's
    // one boat is already taken on the first.
    $page = firstStepsPage(OperatorUser::withRole(Role::Owner), CreateVessel::class)
        ->fillForm($form)
        ->call('create')
        ->assertHasNoFormErrors();

    $redirect = $page->effects['redirect'] ?? null;

    expect($redirect)->toBeString()
        ->and($redirect)->not->toBe(Dashboard::getUrl());
})->group('fast');

it('returns to the dashboard after a period made from the checklist', function (): void {
    $user = OperatorUser::withRole(Role::Owner);
    // The repeater opens with one empty range under a generated key; the
    // range goes into that one.
    $fill = static function (Testable $page, string $el, string $en, int $priority): Testable {
        /** @var array<string, mixed> $ranges */
        $ranges = $page->get('data.dateRanges');

        return $page->fillForm([
            'name' => ['el' => $el, 'en' => $en],
            'priority' => $priority,
            'is_active' => true,
            'dateRanges' => [array_key_first($ranges) => ['starts_on' => '2026-06-01', 'ends_on' => '2026-09-15']],
        ]);
    };

    $fill(firstStepsPage($user, CreateSeason::class, query: fromChecklist()), 'Θερινή', 'Summer', 10)
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(Dashboard::getUrl());

    $page = $fill(firstStepsPage($user, CreateSeason::class), 'Χειμερινή', 'Winter', 20)
        ->call('create')
        ->assertHasNoFormErrors();

    $redirect = $page->effects['redirect'] ?? null;

    expect($redirect)->toBeString()
        ->and($redirect)->not->toBe(Dashboard::getUrl())
        ->and(Tenancy::forTenant($user->tenant, fn (): int => Season::query()->count()))->toBe(2);
})->group('fast');

it('takes a trip started from the checklist to its own page, then back once it is published', function (): void {
    // Creating a trip still opens it on «Πότε φεύγει» — the trip is filled in
    // there — so the marker goes along, and the return happens on publishing.
    $user = OperatorUser::withRole(Role::Owner);

    $page = firstStepsPage($user, CreateProduct::class, query: fromChecklist())
        ->fillForm([
            'title' => ['el' => 'Γύρος του νησιού', 'en' => 'Round the island'],
            'slug' => 'round-the-island',
            'category' => ProductCategory::SharedFullDay->value,
            'mode' => BookingMode::PerSeat->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $draft = Tenancy::forTenant($user->tenant, fn (): Product => Product::query()->sole());

    $page->assertRedirect(
        ProductResource::getUrl('edit', ['record' => $draft])
            . '?tab=' . ProductResource::tabQueryKey('when')
            . '&from=' . FirstSteps::MARKER,
    );

    // A trip complete enough to publish, started from the checklist…
    $trip = Tenancy::forTenant($user->tenant, fn (): Product => publishableDraft());

    firstStepsPage($user, EditProduct::class, ['record' => $trip->uuid], fromChecklist())
        ->callAction('publish')
        ->assertNotified(__('catalog.product.status_actions.published'))
        ->assertRedirect(Dashboard::getUrl());

    // …and one that was not: published where it stands, as before.
    $other = Tenancy::forTenant($user->tenant, fn (): Product => publishableDraft());

    firstStepsPage($user, EditProduct::class, ['record' => $other->uuid])
        ->callAction('publish')
        ->assertNotified(__('catalog.product.status_actions.published'))
        ->assertNoRedirect();

    Tenancy::forTenant($user->tenant, function () use ($trip, $other): void {
        expect($trip->fresh()?->status)->toBe(ProductStatus::Active)
            ->and($other->fresh()?->status)->toBe(ProductStatus::Active);
    });
})->group('fast');

it('offers the home page last and optional, only to an operator who gets one', function (): void {
    // Mike, 25/9: out of the first-time guide, onto this list.
    $user = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant($user->tenant, function (): void {
        expect(array_key_last(Steps::state()))->toBe(Steps::HOME_PAGE)
            ->and(Steps::state()[Steps::HOME_PAGE])->toBeFalse()
            // Nobody needs it to take a first booking.
            ->and(Steps::OPTIONAL)->toContain(Steps::HOME_PAGE)
            ->and(Steps::next())->toBe(Steps::PORT);

        // Done when there is something on the page, not when it was opened.
        HomePageBlock::factory()->ofType(HomeBlockType::Faq)->create();

        expect(Steps::state()[Steps::HOME_PAGE])->toBeTrue();
    });

    $user->tenant->forceFill(['hosted_site_mode' => HostedSiteMode::BookingsOnly])->save();

    Tenancy::forTenant($user->tenant, function (): void {
        expect(Steps::state())->not->toHaveKey(Steps::HOME_PAGE);
    });
})->group('fast');

it('returns to the dashboard once the home page is saved from the checklist', function (): void {
    $user = OperatorUser::withRole(Role::Owner);

    // The editor opens on the page guests are already served; saving it as it
    // stands is enough to make it the operator's own.
    firstStepsPage($user, HomePage::class, query: fromChecklist())
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertRedirect(Dashboard::getUrl());

    firstStepsPage($user, HomePage::class)
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNoRedirect();
})->group('fast');

/** A draft with everything `SaveProduct` asks for before it may be published. */
function publishableDraft(): Product
{
    $product = Product::factory()->create([
        'vessel_id' => Vessel::factory()->create()->getKey(),
        'meeting_point_id' => Port::factory()->create()->getKey(),
        'cancellation_policy_id' => CancellationPolicy::factory()->create(['is_default' => true])->getKey(),
        'status' => ProductStatus::Draft,
    ]);

    $band = AgeBand::factory()->create(['product_id' => $product->getKey()]);
    $plan = RatePlan::factory()->create(['product_id' => $product->getKey()]);
    RatePlanPrice::factory()->create(['rate_plan_id' => $plan->getKey(), 'age_band_id' => $band->getKey()]);

    return $product;
}
