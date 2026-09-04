<?php

declare(strict_types=1);

use App\Domain\Availability\Support\WeekdayMask;
use App\Enums\Role;
use App\Filament\App\Resources\ScheduleRuleResource;
use App\Filament\App\Resources\ScheduleRuleResource\Pages\CreateScheduleRule;
use App\Filament\App\Resources\ScheduleRuleResource\Pages\EditScheduleRule;
use App\Filament\App\Resources\ScheduleRuleResource\Pages\ListScheduleRules;
use App\Models\Product;
use App\Models\ScheduleRule;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
 * Spec CAT-14, AVL-52, AVL-55, SEC-3, TEN-8, I18N-1, CNV-5.
 *
 * The column is a bitmask and the operator is not. What is asserted here is the
 * translation in both directions — seven checkboxes in, one integer stored, and
 * the same seven back out on edit — because a resource that only got the write
 * direction right would show an operator the wrong days the next time they
 * opened the form.
 */

function scheduleTenantOf(User $user): Tenant
{
    return Tenant::query()->findOrFail($user->tenant_id);
}

/** @param array<string, mixed> $params */
function schedulePageAs(User $user, string $page, array $params = []): Testable
{
    tenancy()->initialize(scheduleTenantOf($user));

    return Livewire::actingAs($user)->test($page, $params);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function scheduleFormState(int $productId, array $overrides = []): array
{
    return array_merge([
        'product_id' => $productId,
        'weekdays' => [2, 4],
        'start_time' => '09:00',
        'valid_from' => '2026-06-01',
        'valid_until' => '2026-09-15',
        'generate_days_ahead' => 180,
        'is_active' => true,
    ], $overrides);
}

it('lets an owner and a manager reach the schedules page', function (Role $role): void {
    actingAs(OperatorUser::withRole($role))->get('/app/schedule-rules')->assertSuccessful();
})->with([[Role::Owner], [Role::Manager]])->group('fast');

it('refuses crew the schedules page', function (): void {
    // TEN-8. Crew read the manifest for a departure that already exists;
    // deciding that Tuesdays are now sailing days is not part of that.
    actingAs(OperatorUser::withRole(Role::Crew))->get('/app/schedule-rules')->assertForbidden();
})->group('fast');

it('lists only the signed-in operator schedules', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $other = Tenant::factory()->create();

    $mine = Tenancy::forTenant(scheduleTenantOf($owner), fn (): ScheduleRule => ScheduleRule::factory()->create());
    $theirs = Tenancy::forTenant($other, fn (): ScheduleRule => ScheduleRule::factory()->create());

    schedulePageAs($owner, ListScheduleRules::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
})->group('fast');

it('turns seven checkboxes into the bitmask', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    $product = Tenancy::forTenant(scheduleTenantOf($owner), fn (): Product => Product::factory()->create());

    schedulePageAs($owner, CreateScheduleRule::class)
        ->fillForm(scheduleFormState($product->getKey()))
        ->call('create')
        ->assertHasNoFormErrors();

    Tenancy::forTenant(scheduleTenantOf($owner), function (): void {
        // Tuesday and Thursday: bits 1 and 3, which is 10.
        expect(ScheduleRule::query()->firstOrFail()->weekday_mask)->toBe(10);
    });
})->group('fast');

it('turns the bitmask back into the same seven checkboxes', function (): void {
    // The direction that is easy to forget, and the one whose absence shows an
    // operator the wrong days the next time they open the form.
    $owner = OperatorUser::withRole(Role::Owner);

    $rule = Tenancy::forTenant(
        scheduleTenantOf($owner),
        fn (): ScheduleRule => ScheduleRule::factory()->onDays([2, 4])->create(),
    );

    $page = schedulePageAs($owner, EditScheduleRule::class, ['record' => $rule->getKey()]);

    $page->assertSuccessful();

    expect($page->get('data.weekdays'))->toBe([2, 4]);
})->group('fast');

it('shows a refusal on the checkbox list rather than on a hidden field', function (): void {
    // The Action reports on `weekday_mask`; the form has `weekdays`. Without
    // the re-keying, the operator gets a message attached to nothing.
    $owner = OperatorUser::withRole(Role::Owner);

    $product = Tenancy::forTenant(scheduleTenantOf($owner), fn (): Product => Product::factory()->create());

    schedulePageAs($owner, CreateScheduleRule::class)
        ->fillForm(scheduleFormState($product->getKey(), ['weekdays' => []]))
        ->call('create')
        ->assertHasFormErrors(['weekdays']);
})->group('fast');

it('offers only per-seat trips, rather than refusing one it offered', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    [$seats, $charter] = Tenancy::forTenant(scheduleTenantOf($owner), fn (): array => [
        Product::factory()->create(),
        Product::factory()->perVessel()->create(),
    ]);

    tenancy()->initialize(scheduleTenantOf($owner));

    $options = ScheduleRuleResource::productOptions();

    expect($options)->toHaveKey($seats->getKey())
        ->and($options)->not->toHaveKey($charter->getKey());
})->group('fast');

it('previews the next dates the settings would produce', function (): void {
    // Dates, not instants: AVL-16 allows exactly one class to convert a local
    // time to a UTC departure, and it is the generator's (#26). The question an
    // operator checking a weekday mask is asking is "which days".
    $dates = WeekdayMask::nextDates(
        WeekdayMask::fromDays([2, 4]),
        Carbon::parse('2026-06-01'),
        10,
        Carbon::parse('2026-09-15'),
    );

    expect($dates)->toHaveCount(10)
        ->and($dates[0]->toDateString())->toBe('2026-06-02');
})->group('fast');

it('renders the days as names rather than as the number it stores', function (): void {
    expect(ScheduleRuleResource::daysLabel(WeekdayMask::DAILY))->toBe(__('availability.schedule_rule.table.daily'))
        ->and(ScheduleRuleResource::daysLabel(WeekdayMask::fromDays([2, 4])))
        ->toBe(__('availability.schedule_rule.days.2') . ', ' . __('availability.schedule_rule.days.4'));
})->group('fast');

it('renders schedule labels from lang files, in the operator language', function (string $locale, string $expected): void {
    // Over HTTP, because `SetLocale` runs in the panel middleware that a
    // Livewire component test bypasses. A row has to exist too, or Filament
    // renders the empty state instead of the header row.
    $owner = OperatorUser::withRole(Role::Owner);
    $owner->update(['locale' => $locale]);

    Tenancy::forTenant(scheduleTenantOf($owner), fn (): ScheduleRule => ScheduleRule::factory()->create());

    actingAs($owner)->get('/app/schedule-rules')
        ->assertSuccessful()
        ->assertSee($expected)
        ->assertDontSee('availability.schedule_rule.');
})->with([
    ['el', 'Ημέρες'],
    ['en', 'Days'],
])->group('fast', 'i18n');
