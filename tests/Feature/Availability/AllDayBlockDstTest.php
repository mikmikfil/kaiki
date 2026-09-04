<?php

declare(strict_types=1);

use App\Domain\Availability\Actions\CreateVesselBlock;
use App\Enums\BlockReason;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Models\VesselBlock;
use App\Support\Tenancy;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| An all-day block is not 24 hours — spec AVL-4, AVL-14
|--------------------------------------------------------------------------
|
| §2.4 stores a real window for an all-day block (00:00 → the next midnight)
| rather than a null window plus a flag, *"so overlap maths never
| special-cases"*. The subtlety is what "all day" means: in Europe/Athens the
| last Sunday of October is **25 hours** and the last Sunday of March is **23**.
|
| Getting it wrong is a maintenance block that ends an hour before the boat is
| back — on one specific day a year, in the direction that lets a booking
| through.
|
*/

function blockTenant(callable $callback): mixed
{
    return Tenancy::forTenant(Tenant::factory()->create(['timezone' => 'Europe/Athens']), $callback);
}

/** @param array<string, mixed> $attributes */
function createBlock(array $attributes): VesselBlock
{
    $vessel = Vessel::factory()->create(['capacity_max' => 30]);

    return app(CreateVesselBlock::class)($vessel, array_merge([
        'reason' => BlockReason::Maintenance,
    ], $attributes));
}

it('covers a whole ordinary day', function (): void {
    blockTenant(function (): void {
        $block = createBlock(['is_all_day' => true, 'local_date' => '2026-07-04']);

        expect($block->starts_at_utc->toDateTimeString())->toBe('2026-07-03 21:00:00')
            ->and($block->ends_at_utc->toDateTimeString())->toBe('2026-07-04 21:00:00')
            ->and($block->window()->minutes())->toBe(24 * 60);
    });
})->group('fast');

it('covers 25 hours on the autumn fall-back day', function (): void {
    blockTenant(function (): void {
        // 25 October 2026: the clocks go back, and the day is an hour longer.
        // A block that assumed 24 would end at 23:00 local, with the boat still
        // out of the water.
        $block = createBlock(['is_all_day' => true, 'local_date' => '2026-10-25']);

        expect($block->window()->minutes())->toBe(25 * 60)
            ->and($block->local_end_date->toDateString())->toBe('2026-10-25');
    });
})->group('fast');

it('covers 23 hours on the spring-forward day', function (): void {
    blockTenant(function (): void {
        $block = createBlock(['is_all_day' => true, 'local_date' => '2026-03-29']);

        expect($block->window()->minutes())->toBe(23 * 60);
    });
})->group('fast');

it('covers a multi-day range across a transition, in full', function (): void {
    blockTenant(function (): void {
        // Three days spanning the fall-back: 24 + 25 + 24.
        $block = createBlock([
            'is_all_day' => true,
            'local_date' => '2026-10-24',
            'local_end_date' => '2026-10-26',
        ]);

        expect($block->window()->minutes())->toBe((24 + 25 + 24) * 60);
    });
})->group('fast');

it('reports the end date the operator typed, not the exclusive one', function (): void {
    blockTenant(function (): void {
        // The stored window runs to the start of the 7th; an operator who typed
        // "to the 6th" must read "to the 6th" back, or every screen looks wrong
        // by a day.
        $block = createBlock([
            'is_all_day' => true,
            'local_date' => '2026-07-04',
            'local_end_date' => '2026-07-06',
        ]);

        expect($block->local_end_date->toDateString())->toBe('2026-07-06')
            ->and($block->ends_at_utc->toDateTimeString())->toBe('2026-07-06 21:00:00');
    });
})->group('fast');

it('stores a timed block through the one conversion authority', function (): void {
    blockTenant(function (): void {
        $block = createBlock([
            'local_date' => '2026-07-04',
            'start_time' => '12:00',
            'end_time' => '16:00',
        ]);

        expect($block->starts_at_utc->toDateTimeString())->toBe('2026-07-04 09:00:00')
            ->and($block->ends_at_utc->toDateTimeString())->toBe('2026-07-04 13:00:00')
            ->and($block->is_all_day)->toBeFalse();
    });
})->group('fast');

it('refuses a timed block at a local time that does not exist', function (): void {
    blockTenant(function (): void {
        // ADR-0016. Unlike a departure there is an easy alternative — move it
        // half an hour — so the refusal names the field.
        createBlock([
            'local_date' => '2026-03-29',
            'start_time' => '03:30',
            'end_time' => '05:00',
        ]);
    });
})->throws(ValidationException::class)->group('fast');

it('refuses a window that ends before it starts', function (): void {
    blockTenant(function (): void {
        createBlock([
            'local_date' => '2026-07-04',
            'start_time' => '16:00',
            'end_time' => '12:00',
        ]);
    });
})->throws(ValidationException::class)->group('fast');
