<?php

declare(strict_types=1);

use App\Enums\DepositType;
use App\Enums\Role;
use App\Filament\App\Resources\ProductResource\Pages\EditProduct;
use App\Filament\App\Resources\ProductResource\RelationManagers\RatePlansRelationManager;
use App\Filament\App\Resources\RatePlanResource\Pages\EditRatePlan;
use App\Filament\App\Resources\RatePlanResource\Pages\ListRatePlans;
use App\Filament\Forms\MoneyInput;
use App\Models\AgeBand;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
 * Spec CAT-10, PRC-23, SEC-3, TEN-8, CNV-1, CNV-5.
 *
 * A rate plan is money, so `ManagePricing` governs it: owner and manager, never
 * crew. Filament *allows* an action when no policy is registered, which is why
 * the assertions below are about the policy rather than about a missing link.
 *
 * The form is thin on purpose — every rule is in the Action — so what is worth
 * testing here is the part only the form does: turning «150,50» into 15050
 * without a float in between.
 */

function ratePlanTenantOf(User $user): Tenant
{
    return Tenant::query()->findOrFail($user->tenant_id);
}

/** @param array<string, mixed> $params */
function ratePlanPageAs(User $user, string $page, array $params = []): Testable
{
    tenancy()->initialize(ratePlanTenantOf($user));

    return Livewire::actingAs($user)->test($page, $params);
}

/**
 * The surviving way to make a price list: from inside the trip.
 *
 * «Προσθήκη Τιμοκαταλόγου» was removed from this screen on 2026-09-23 — a
 * price cannot exist before the trip it prices, so its first question was
 * «ποια εκδρομή;» and an operator who can answer that is already on the trip.
 * The rules it used to exercise did not go away with it, so the tests that
 * exercised them come through here instead.
 */
function ratePlanManagerFor(User $user, Product $product): Testable
{
    tenancy()->initialize(ratePlanTenantOf($user));

    return Livewire::actingAs($user)->test(RatePlansRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ]);
}

it('lets an owner and a manager reach the rate plans page', function (Role $role): void {
    actingAs(OperatorUser::withRole($role))->get('/app/rate-plans')->assertSuccessful();
})->with([[Role::Owner], [Role::Manager]])->group('fast');

it('refuses crew the rate plans page', function (): void {
    // TEN-8: crew get the manifest. What a seat cost is not on it.
    actingAs(OperatorUser::withRole(Role::Crew))->get('/app/rate-plans')->assertForbidden();
})->group('fast');

it('lists only the signed-in operator plans', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $other = Tenant::factory()->create();

    $mine = Tenancy::forTenant(
        ratePlanTenantOf($owner),
        fn (): RatePlan => RatePlan::factory()->create(),
    );

    $theirs = Tenancy::forTenant(
        $other,
        fn (): RatePlan => RatePlan::factory()->create(),
    );

    ratePlanPageAs($owner, ListRatePlans::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
})->group('fast');

it('creates a plan with per-band prices through the form', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    [$product, $base] = Tenancy::forTenant(ratePlanTenantOf($owner), function (): array {
        $product = Product::factory()->create();
        $base = AgeBand::factory()->create(['product_id' => $product->getKey()]);

        return [$product, $base];
    });

    ratePlanManagerFor($owner, $product)
        ->callTableAction('create', data: [
            'season_id' => null,
            'name' => 'Base',
            'deposit_type' => DepositType::Percent->value,
            'deposit_percent' => 30,
            'min_lead_time_hours' => 0,
            'band_prices' => [
                ['age_band_id' => $base->getKey(), 'price_cents' => '50,00'],
            ],
        ])
        ->assertHasNoTableActionErrors();

    Tenancy::forTenant(ratePlanTenantOf($owner), function () use ($base): void {
        $plan = RatePlan::query()->firstOrFail();

        expect($plan->deposit_type)->toBe(DepositType::Percent)
            // «50,00» typed on a Greek keyboard, stored as an integer.
            ->and($plan->prices()->where('age_band_id', $base->getKey())->value('price_cents'))->toBe(5000);
    });
})->group('fast');

it('shows a validation error rather than a stack trace when the Action refuses', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    $product = Tenancy::forTenant(ratePlanTenantOf($owner), function (): Product {
        $product = Product::factory()->create();
        AgeBand::factory()->create(['product_id' => $product->getKey()]);
        RatePlan::factory()->create(['product_id' => $product->getKey()]);

        return $product;
    });

    $base = Tenancy::forTenant(
        ratePlanTenantOf($owner),
        fn (): AgeBand => $product->ageBands()->firstOrFail(),
    );

    ratePlanManagerFor($owner, $product)
        ->callTableAction('create', data: [
            'season_id' => null,
            'deposit_type' => DepositType::None->value,
            'min_lead_time_hours' => 0,
            'band_prices' => [
                ['age_band_id' => $base->getKey(), 'price_cents' => '50,00'],
            ],
        ])
        ->assertHasTableActionErrors(['season_id']);
})->group('fast');

it('renders a plan for editing with its band prices filled in', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    $plan = Tenancy::forTenant(ratePlanTenantOf($owner), function (): RatePlan {
        $product = Product::factory()->create();
        $base = AgeBand::factory()->create(['product_id' => $product->getKey()]);
        $plan = RatePlan::factory()->create(['product_id' => $product->getKey()]);

        $plan->prices()->create(['age_band_id' => $base->getKey(), 'price_cents' => 7500]);

        return $plan;
    });

    $page = ratePlanPageAs($owner, EditRatePlan::class, ['record' => $plan->getKey()]);

    $page->assertSuccessful();

    // Repeater rows are keyed by a generated id rather than an index, so the
    // row is taken by position — asserting on `band_prices.0` would pass only
    // until Filament changed how it keys them.
    /** @var array<string, array<string, mixed>> $rows */
    $rows = $page->get('data.band_prices');
    $row = array_values($rows)[0] ?? [];

    // The stored 7500 arrives in form state as decimal text, which is what the
    // operator edits and what `MoneyInput` parses back.
    expect($row['price_cents'] ?? null)->toBe('75.00')
        ->and((int) ($row['age_band_id'] ?? 0))->toBe((int) $plan->prices()->value('age_band_id'));
})->group('fast');

it('parses a money field without ever producing a float', function (string $typed, ?int $cents): void {
    // CNV-1 says "not even transiently in form state", and this is the only
    // place in the panel where decimal text becomes an integer.
    expect(MoneyInput::toCents($typed))->toBe($cents);
})->with([
    ['150,50', 15050],
    ['150.50', 15050],
    ['150', 15000],
    [' 1 500,05 ', 150005],
    ['0,01', 1],
    ['', null],
    ['abc', null],
])->group('fast');

it('renders integer cents back as decimal text', function (): void {
    expect(MoneyInput::toDecimal(15050))->toBe('150.50')
        ->and(MoneyInput::toDecimal(null))->toBeNull();
})->group('fast');

it('says where age bands come from, instead of showing an empty «Τιμές» box', function (): void {
    // Product owner, 2026-09-22: «στις τιμές μπορώ να προσθέσω νέα τιμή για μια
    // εκδρομή αλλά δεν μπορώ να βάλω ηλικίες κλπ». He was right that he could
    // not, and right to expect the screen to say so: a band belongs to the trip,
    // and this form only prices the ones that exist.
    //
    // Asserted on the *edit* form since 2026-09-23: with the create page gone
    // this is the only screen that still renders the hint, and a band-less trip
    // with a saved plan is exactly what an import can leave behind.
    $owner = OperatorUser::withRole(Role::Owner);

    $plan = Tenancy::forTenant(ratePlanTenantOf($owner), function (): RatePlan {
        $bandless = Product::factory()->create();

        return RatePlan::factory()->create(['product_id' => $bandless->getKey()]);
    });

    $bandless = Tenancy::forTenant(
        ratePlanTenantOf($owner),
        fn (): Product => Product::query()->findOrFail($plan->product_id),
    );

    ratePlanPageAs($owner, EditRatePlan::class, ['record' => $plan->getKey()])
        ->assertSee((string) $bandless->title)
        ->assertSee('/app/products/' . $bandless->getRouteKey() . '/edit', escape: false);
})->group('fast');

it('drops the hint once the trip has bands to price', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    $plan = Tenancy::forTenant(ratePlanTenantOf($owner), function (): RatePlan {
        $product = Product::factory()->create();
        AgeBand::factory()->create(['product_id' => $product->getKey()]);

        return RatePlan::factory()->create(['product_id' => $product->getKey()]);
    });

    ratePlanPageAs($owner, EditRatePlan::class, ['record' => $plan->getKey()])
        ->assertDontSee(__('pricing.rate_plan.form.prices.trip_gone'));
})->group('fast');

it('offers no «Προσθήκη Τιμοκαταλόγου», because a price cannot precede its trip', function (): void {
    // Mike, 2026-09-23. Both ways of making a plan run through the trip: the
    // first is written by `CreateProduct`, the rest come from the trip's own
    // «Τιμές» tab. A third door whose first question was «ποια εκδρομή;» was
    // asking something the operator had already answered by opening the trip.
    $owner = OperatorUser::withRole(Role::Owner);

    actingAs($owner)->get('/app/rate-plans/create')->assertNotFound();

    ratePlanPageAs($owner, ListRatePlans::class)
        ->assertDontSee(__('filament-actions::create.single.label'));
})->group('fast');

it('sends an operator with no trips to make one, instead of an empty table', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    ratePlanPageAs($owner, ListRatePlans::class)
        ->assertSee(__('pricing.rate_plan.empty.no_trips.heading'))
        ->assertSee(__('pricing.rate_plan.empty.no_trips.action'));
})->group('fast');

it('stops offering to make a trip once one exists', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant(ratePlanTenantOf($owner), fn (): Product => Product::factory()->create());

    ratePlanPageAs($owner, ListRatePlans::class)
        ->assertDontSee(__('pricing.rate_plan.empty.no_trips.heading'))
        ->assertSee(__('pricing.rate_plan.empty.none.heading'));
})->group('fast');

it('will not move a saved plan to another trip', function (): void {
    // The field is shown so the plan says what it prices, and disabled so the
    // one edit nobody should make from here cannot be made: repointing a plan
    // would leave every price row on another trip's age bands.
    $owner = OperatorUser::withRole(Role::Owner);

    $plan = Tenancy::forTenant(ratePlanTenantOf($owner), function (): RatePlan {
        $product = Product::factory()->create();
        AgeBand::factory()->create(['product_id' => $product->getKey()]);

        return RatePlan::factory()->create(['product_id' => $product->getKey()]);
    });

    $page = ratePlanPageAs($owner, EditRatePlan::class, ['record' => $plan->getKey()]);

    $page->assertFormFieldIsDisabled('product_id');
})->group('fast');
