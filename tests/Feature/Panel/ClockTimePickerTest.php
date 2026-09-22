<?php

declare(strict_types=1);

use App\Enums\BookingMode;
use App\Enums\Role;
use App\Filament\App\Resources\DepartureResource\Pages\CreateDeparture;
use App\Filament\App\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\App\Resources\ScheduleRuleResource\Pages\CreateScheduleRule;
use App\Filament\App\Resources\VesselBlockResource\Pages\CreateVesselBlock;
use App\Models\Product;
use App\Models\ScheduleRule;
use App\Models\Tenant;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Form;
use Livewire\Livewire;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| A time on its own is a clock on the quay, never converted (2026-09-17)
|--------------------------------------------------------------------------
|
| The panel gives every date-time picker the tenant's timezone, and a time
| picker is a date-time picker. On a field that holds a plain local time —
| a trip's departure hour, a rule's start, a programme stop — that turned
| 09:00 into 06:00 on save for an operator in Athens. Every time-only picker
| in the operator panel must opt out, and this finds any that does not.
|
*/

it('never converts a time-only picker to or from the tenant timezone', function (string $page): void {
    $owner = OperatorUser::withRole(Role::Owner);
    tenancy()->initialize(Tenant::query()->findOrFail($owner->tenant_id));

    $form = Livewire::actingAs($owner)->test($page)->instance()->form;

    if (! $form instanceof Form) {
        throw new RuntimeException("{$page} has no form to inspect.");
    }

    $pickers = collect($form->getFlatComponents(withHidden: true))
        ->filter(static fn (mixed $component): bool => $component instanceof TimePicker);

    expect($pickers)->not->toBeEmpty();

    foreach ($pickers as $picker) {
        expect($picker->getTimezone())->toBe('UTC', "{$page}: {$picker->getName()}");
    }
})->with([
    'trip' => CreateProduct::class,
    'schedule rule' => CreateScheduleRule::class,
    'departure' => CreateDeparture::class,
    'vessel block' => CreateVesselBlock::class,
])->group('fast');

/*
|--------------------------------------------------------------------------
| And a date on its own is a day on a calendar (2026-09-22)
|--------------------------------------------------------------------------
|
| `DatePicker` extends `DateTimePicker`, so it inherited the tenant timezone
| above — and a `date` column has no time in it to absorb the shift. Midnight
| on 1 June in Athens is 21:00 on 31 May in UTC, and the column keeps the 31st.
| Every date field in the panel is a calendar day: a rule's window, a period's
| range, a coupon's validity. All of them were a day early.
|
*/

it('never converts a date-only picker to or from the tenant timezone', function (string $page): void {
    $owner = OperatorUser::withRole(Role::Owner);
    tenancy()->initialize(Tenant::query()->findOrFail($owner->tenant_id));

    $form = Livewire::actingAs($owner)->test($page)->instance()->form;

    if (! $form instanceof Form) {
        throw new RuntimeException("{$page} has no form to inspect.");
    }

    $pickers = collect($form->getFlatComponents(withHidden: true))
        ->filter(static fn (mixed $component): bool => $component instanceof DatePicker);

    expect($pickers)->not->toBeEmpty();

    foreach ($pickers as $picker) {
        expect($picker->getTimezone())->toBe('UTC', "{$page}: {$picker->getName()}");
    }
})->with([
    'trip' => CreateProduct::class,
    'schedule rule' => CreateScheduleRule::class,
])->group('fast');

it('stores a schedule window on the day the operator typed', function (): void {
    // The bug itself rather than the wiring: an Athens operator typing 1 June
    // got 31 May, and the generator made departures from it.
    $owner = OperatorUser::withRole(Role::Owner);
    tenancy()->initialize(Tenant::query()->findOrFail($owner->tenant_id));

    $product = Product::factory()->create(['mode' => BookingMode::PerSeat]);

    Livewire::actingAs($owner)->test(CreateScheduleRule::class)
        ->fillForm([
            'product_id' => $product->getKey(),
            'weekdays' => [1],
            'start_time' => '09:00',
            'valid_from' => '2027-06-01',
            'valid_until' => '2027-09-30',
            'generate_days_ahead' => 180,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = ScheduleRule::query()->sole();

    expect($rule->valid_from->toDateString())->toBe('2027-06-01')
        ->and($rule->valid_until?->toDateString())->toBe('2027-09-30');
})->group('fast');
