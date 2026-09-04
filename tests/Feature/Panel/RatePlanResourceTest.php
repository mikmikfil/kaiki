<?php

declare(strict_types=1);

use App\Enums\DepositType;
use App\Enums\Role;
use App\Filament\App\Resources\RatePlanResource\Pages\CreateRatePlan;
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

    ratePlanPageAs($owner, CreateRatePlan::class)
        ->fillForm([
            'product_id' => $product->getKey(),
            'season_id' => null,
            'name' => 'Base',
            'deposit_type' => DepositType::Percent->value,
            'deposit_percent' => 30,
            'min_lead_time_hours' => 0,
            'band_prices' => [
                ['age_band_id' => $base->getKey(), 'price_cents' => '50,00'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

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

    ratePlanPageAs($owner, CreateRatePlan::class)
        ->fillForm([
            'product_id' => $product->getKey(),
            'season_id' => null,
            'deposit_type' => DepositType::None->value,
            'min_lead_time_hours' => 0,
            'band_prices' => [
                ['age_band_id' => $base->getKey(), 'price_cents' => '50,00'],
            ],
        ])
        ->call('create')
        ->assertHasFormErrors(['season_id']);
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
