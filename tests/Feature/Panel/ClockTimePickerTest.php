<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Filament\App\Resources\DepartureResource\Pages\CreateDeparture;
use App\Filament\App\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\App\Resources\ScheduleRuleResource\Pages\CreateScheduleRule;
use App\Filament\App\Resources\VesselBlockResource\Pages\CreateVesselBlock;
use App\Models\Tenant;
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
