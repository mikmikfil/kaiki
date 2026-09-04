<?php

declare(strict_types=1);

use App\Domain\Availability\Support\ScheduleRuleCapacityResolver;
use App\Models\Product;
use App\Models\ScheduleRule;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;

/*
|--------------------------------------------------------------------------
| How many seats a departure gets — spec AVL-55, data-model §1.9
|--------------------------------------------------------------------------
|
| `capacity_override ?? products.max_pax`, capped at the vessel's
| `capacity_max`. The cap is the assertion that matters: a passenger
| certificate is a legal document, and a rule that oversells it is discovered by
| the port authority rather than by a test.
|
*/

function capacityTenant(callable $callback): mixed
{
    return Tenancy::forTenant(Tenant::factory()->create(), $callback);
}

it('uses the product maximum when there is no override', function (): void {
    capacityTenant(function (): void {
        $vessel = Vessel::factory()->create(['capacity_max' => 30]);
        $product = Product::factory()->create(['vessel_id' => $vessel->getKey(), 'max_pax' => 12]);

        $rule = ScheduleRule::factory()->create(['product_id' => $product->getKey()]);

        expect(ScheduleRuleCapacityResolver::capacity($rule->refresh()))->toBe(12);
    });
})->group('fast');

it('prefers the override', function (): void {
    capacityTenant(function (): void {
        $vessel = Vessel::factory()->create(['capacity_max' => 30]);
        $product = Product::factory()->create(['vessel_id' => $vessel->getKey(), 'max_pax' => 12]);

        $rule = ScheduleRule::factory()->withCapacity(8)->create(['product_id' => $product->getKey()]);

        expect(ScheduleRuleCapacityResolver::capacity($rule->refresh()))->toBe(8);
    });
})->group('fast');

it('caps the override at the vessel certificate', function (): void {
    capacityTenant(function (): void {
        // The legal ceiling. An operator typing 40 into a rule for a boat
        // licensed for 30 has made a mistake the port authority would find.
        $vessel = Vessel::factory()->create(['capacity_max' => 30]);
        $product = Product::factory()->create(['vessel_id' => $vessel->getKey(), 'max_pax' => 12]);

        $rule = ScheduleRule::factory()->withCapacity(40)->create(['product_id' => $product->getKey()]);

        expect(ScheduleRuleCapacityResolver::capacity($rule->refresh()))->toBe(30);
    });
})->group('fast');

it('caps against the rule vessel rather than the product vessel', function (): void {
    capacityTenant(function (): void {
        // The whole reason the override exists: the same trip, on a smaller
        // boat, on Sundays.
        $big = Vessel::factory()->create(['capacity_max' => 30]);
        $small = Vessel::factory()->create(['capacity_max' => 6]);

        $product = Product::factory()->create(['vessel_id' => $big->getKey(), 'max_pax' => 12]);
        $rule = ScheduleRule::factory()->onVessel($small)->create(['product_id' => $product->getKey()]);

        expect(ScheduleRuleCapacityResolver::vesselId($rule->refresh()))->toBe($small->getKey())
            ->and(ScheduleRuleCapacityResolver::capacity($rule))->toBe(6);
    });
})->group('fast');

it('inherits the product vessel when the rule names none', function (): void {
    capacityTenant(function (): void {
        $vessel = Vessel::factory()->create(['capacity_max' => 30]);
        $product = Product::factory()->create(['vessel_id' => $vessel->getKey()]);

        $rule = ScheduleRule::factory()->create(['product_id' => $product->getKey()]);

        expect(ScheduleRuleCapacityResolver::vesselId($rule->refresh()))->toBe($vessel->getKey());
    });
})->group('fast');

it('answers without a vessel rather than throwing', function (): void {
    capacityTenant(function (): void {
        // A product can exist without a boat while it is being built — CAT-15
        // refuses to publish it, and a resolver that threw here would break the
        // panel preview on a draft.
        $product = Product::factory()->create(['vessel_id' => null, 'max_pax' => 12]);
        $rule = ScheduleRule::factory()->create(['product_id' => $product->getKey()]);

        expect(ScheduleRuleCapacityResolver::vesselCeiling($rule->refresh()))->toBeNull()
            ->and(ScheduleRuleCapacityResolver::capacity($rule))->toBe(12);
    });
})->group('fast');
