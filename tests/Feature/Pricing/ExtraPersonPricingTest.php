<?php

declare(strict_types=1);

use App\Domain\Hosted\Actions\BuildProductPage;
use App\Domain\Pricing\Actions\ComputePrice;
use App\Domain\Pricing\Actions\SaveRatePlan;
use App\Enums\AuditAction;
use App\Filament\Admin\Resources\TenantResource\Pages\EditTenant;
use App\Models\AgeBand;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| «X € for up to N people, +Y € for each extra» (product owner, 2026-09-17)
|--------------------------------------------------------------------------
|
| A pricing model the platform switches on per operator. Off, nothing about a
| boat price changes, even on a plan that still carries the numbers.
|
*/

/** A whole-boat trip at 2.500 € for up to ten, 50 € for each one more. */
function extraPersonCharter(): Product
{
    $product = Product::factory()->perVessel()->create();

    RatePlan::factory()->perVessel(250000)->create([
        'product_id' => $product->getKey(),
        'included_pax' => 10,
        'extra_pax_price_cents' => 5000,
    ]);

    return $product->refresh();
}

it('is off for every operator until the platform switches it on', function (): void {
    expect(Tenant::factory()->create()->usesExtraPersonPricing())->toBeFalse();
})->group('fast');

it('charges each person past the included number as its own line', function (): void {
    Tenancy::forTenant(Tenant::factory()->create(['extra_person_pricing_enabled' => true]), function (): void {
        $quote = app(ComputePrice::class)(extraPersonCharter(), Carbon::parse('2026-07-04'), ['adult' => 13]);
        $lines = $quote->snapshot->toArray()['lines'];

        expect($lines)->toHaveCount(2)
            ->and($lines[1]['ref'])->toBe('extra_pax')
            ->and($lines[1]['qty'])->toBe(3)
            ->and($lines[1]['unit_price_cents'])->toBe(5000)
            ->and($quote->totalCents)->toBe(265000);
    });
})->group('fast');

it('charges nothing extra at or under the included number', function (): void {
    Tenancy::forTenant(Tenant::factory()->create(['extra_person_pricing_enabled' => true]), function (): void {
        $quote = app(ComputePrice::class)(extraPersonCharter(), Carbon::parse('2026-07-04'), ['adult' => 10]);

        expect($quote->snapshot->toArray()['lines'])->toHaveCount(1)
            ->and($quote->totalCents)->toBe(250000);
    });
})->group('fast');

it('ignores the numbers while the operator does not have the switch', function (): void {
    Tenancy::forTenant(Tenant::factory()->create(), function (): void {
        $quote = app(ComputePrice::class)(extraPersonCharter(), Carbon::parse('2026-07-04'), ['adult' => 14]);

        expect($quote->totalCents)->toBe(250000);
    });
})->group('fast');

it('counts only the people who take a seat when the trip has bands', function (): void {
    Tenancy::forTenant(Tenant::factory()->create(['extra_person_pricing_enabled' => true]), function (): void {
        $product = extraPersonCharter();
        AgeBand::factory()->create(['product_id' => $product->getKey(), 'code' => 'adult']);
        AgeBand::factory()->create([
            'product_id' => $product->getKey(),
            'code' => 'infant',
            'min_age' => 0,
            'max_age' => 2,
            'is_base' => false,
            'counts_toward_capacity' => false,
        ]);

        $quote = app(ComputePrice::class)($product, Carbon::parse('2026-07-04'), ['adult' => 11, 'infant' => 2]);

        expect($quote->totalCents)->toBe(255000);
    });
})->group('fast');

it('refuses the rule on a trip sold per seat, and half of it on a boat', function (): void {
    Tenancy::forTenant(Tenant::factory()->create(['extra_person_pricing_enabled' => true]), function (): void {
        $seat = Product::factory()->create();

        expect(fn () => app(SaveRatePlan::class)(new RatePlan, $seat, ['included_pax' => 10, 'extra_pax_price_cents' => 5000]))
            ->toThrow(ValidationException::class);

        $boat = Product::factory()->perVessel()->create();

        expect(fn () => app(SaveRatePlan::class)(new RatePlan, $boat, ['vessel_price_cents' => 250000, 'included_pax' => 10]))
            ->toThrow(ValidationException::class);
    });
})->group('fast');

it('says the rule beside the boat price on the trip page', function (): void {
    Tenancy::forTenant(Tenant::factory()->create(['extra_person_pricing_enabled' => true]), function (): void {
        $page = app(BuildProductPage::class)(extraPersonCharter(), 'el');

        expect($page['extraPersonNote'])->toContain('10')->toContain('50');
    });
})->group('fast');

it('is switched on from the platform admin with a reason in the operator trail', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->superAdmin()->create();

    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::actingAs($admin)
        ->test(EditTenant::class, ['record' => $tenant->getRouteKey()])
        ->assertFormSet(['extra_person_pricing_enabled' => false])
        ->fillForm(['extra_person_pricing_enabled' => true])
        ->callAction('save', ['auditReason' => 'Τα ιδιωτικά τους τιμολογούνται έως 10 άτομα και +50 € ανά επιπλέον.'])
        ->assertHasNoErrors();

    expect($tenant->refresh()->usesExtraPersonPricing())->toBeTrue();

    Tenancy::forTenant($tenant, function (): void {
        $entry = AuditLog::query()->where('action', AuditAction::TenantUpdated->value)->sole();

        expect($entry->context)->toEqual([
            'extra_person_pricing_enabled_from' => null,
            'extra_person_pricing_enabled_to' => true,
        ]);
    });
})->group('fast');
