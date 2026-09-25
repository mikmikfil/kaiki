<?php

declare(strict_types=1);

use App\Domain\Availability\Support\WeekdayMask;
use App\Domain\Catalog\Actions\SaveScheduleRule;
use App\Enums\BookingMode;
use App\Models\Product;
use App\Models\ScheduleRule;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Schedule rules — spec CAT-14, AVL-52
|--------------------------------------------------------------------------
|
| Four refusals, and each prevents a rule that would sit in the panel looking
| configured while producing nothing. That is worse than an error, because
| nobody goes looking at a rule that appears to be working.
|
*/

function scheduleTenant(callable $callback): mixed
{
    return Tenancy::forTenant(Tenant::factory()->create(), $callback);
}

/** @param array<string, mixed> $overrides */
function saveRule(Product $product, array $overrides = [], ?ScheduleRule $rule = null): ScheduleRule
{
    return app(SaveScheduleRule::class)(
        $rule ?? new ScheduleRule,
        $product,
        array_merge([
            'weekday_mask' => WeekdayMask::DAILY,
            'start_time' => '09:00',
            'valid_from' => '2026-06-01',
            'valid_until' => '2026-09-15',
            'is_active' => true,
        ], $overrides),
    );
}

it('saves a rule on a per-seat product', function (): void {
    scheduleTenant(function (): void {
        $rule = saveRule(Product::factory()->create(), ['weekday_mask' => WeekdayMask::fromDays([2, 4])]);

        expect($rule->exists)->toBeTrue()
            ->and($rule->weekday_mask)->toBe(10)
            ->and($rule->coversDate(Carbon::parse('2026-06-02')))->toBeTrue()
            ->and($rule->coversDate(Carbon::parse('2026-06-03')))->toBeFalse();
    });
})->group('fast');

it('refuses a rule on a whole-boat product', function (): void {
    scheduleTenant(function (): void {
        // CAT-14: rules produce departures, and departures are the per-seat
        // sellable instance. A charter is booked as a window against the boat's
        // calendar.
        saveRule(Product::factory()->perVessel()->create());
    });
})->throws(ValidationException::class)->group('fast');

it('refuses a rule on a quote product', function (): void {
    scheduleTenant(function (): void {
        saveRule(Product::factory()->create(['mode' => BookingMode::Quote]));
    });
})->throws(ValidationException::class)->group('fast');

it('refuses an empty weekday mask', function (): void {
    scheduleTenant(function (): void {
        // Passes every column constraint and generates nothing on any day. An
        // operator who cleared all seven boxes meant to pause the rule, and
        // `is_active` is how you pause a rule.
        saveRule(Product::factory()->create(), ['weekday_mask' => 0]);
    });
})->throws(ValidationException::class)->group('fast');

it('refuses a mask above the seven days', function (): void {
    scheduleTenant(function (): void {
        saveRule(Product::factory()->create(), ['weekday_mask' => 255]);
    });
})->throws(ValidationException::class)->group('fast');

it('refuses a validity window that ends before it starts', function (): void {
    scheduleTenant(function (): void {
        // Always a mistyped year. Refused rather than normalised: silently
        // swapping them would generate a season the operator did not ask for.
        saveRule(Product::factory()->create(), [
            'valid_from' => '2026-09-15',
            'valid_until' => '2026-06-01',
        ]);
    });
})->throws(ValidationException::class)->group('fast');

it('accepts a one-day window', function (): void {
    scheduleTenant(function (): void {
        // Both bounds inclusive, so the same day on both sides is valid.
        $rule = saveRule(Product::factory()->create(), [
            'valid_from' => '2026-06-01',
            'valid_until' => '2026-06-01',
        ]);

        expect($rule->coversDate(Carbon::parse('2026-06-01')))->toBeTrue()
            ->and($rule->coversDate(Carbon::parse('2026-06-02')))->toBeFalse();
    });
})->group('fast');

it('accepts an open-ended window', function (): void {
    scheduleTenant(function (): void {
        $rule = saveRule(Product::factory()->create(), ['valid_until' => null]);

        expect($rule->valid_until)->toBeNull()
            ->and($rule->coversDate(Carbon::parse('2030-01-01')))->toBeTrue();
    });
})->group('fast');

/*
| The fourth refusal (product owner, 2026-09-22)
|
| *«Σε μια εκδρομή έβαλα 2πλό δρομολόγιο και δεν μου έβγαλε error — ίδια μέρα,
| ίδια ώρα, τα πάντα.»* Nothing broke, which is the problem: the departures
| table is unique on the instant, so the second rule produces no row at all and
| sits there looking configured. Two rows the operator cannot tell apart is the
| worse half — pausing or editing the wrong one changes nothing.
*/

it('refuses a second rule for a sailing that already has one', function (): void {
    scheduleTenant(function (): void {
        $product = Product::factory()->create();
        saveRule($product, ['weekday_mask' => WeekdayMask::fromDays([2])]);

        saveRule($product, ['weekday_mask' => WeekdayMask::fromDays([2])]);
    });
})->throws(ValidationException::class)->group('fast');

it('refuses a rule that overlaps an existing one on only one day', function (): void {
    scheduleTenant(function (): void {
        // Tuesday 09:00 and «Tuesday, Thursday 09:00» are the same collision on
        // Tuesdays, though no single column matches.
        $product = Product::factory()->create();
        saveRule($product, ['weekday_mask' => WeekdayMask::fromDays([2])]);

        saveRule($product, ['weekday_mask' => WeekdayMask::fromDays([2, 4])]);
    });
})->throws(ValidationException::class)->group('fast');

it('allows the same days at another time, or the same time in another season', function (): void {
    scheduleTenant(function (): void {
        $product = Product::factory()->create();
        saveRule($product, ['weekday_mask' => WeekdayMask::fromDays([2])]);

        // Two sailings a day is the ordinary case, not a clash.
        $evening = saveRule($product, ['weekday_mask' => WeekdayMask::fromDays([2]), 'start_time' => '18:00']);

        // Same days, same hour, a window that opens after the first one closes.
        $autumn = saveRule($product, [
            'weekday_mask' => WeekdayMask::fromDays([2]),
            'valid_from' => '2026-09-16',
            'valid_until' => '2026-10-31',
        ]);

        // Another day of the week entirely.
        $sunday = saveRule($product, ['weekday_mask' => WeekdayMask::fromDays([7])]);

        expect([$evening->exists, $autumn->exists, $sunday->exists])->toBe([true, true, true]);
    });
})->group('fast');

it('does not clash with a paused rule, or count itself when re-saved', function (): void {
    scheduleTenant(function (): void {
        $product = Product::factory()->create();

        // Pausing the old one and writing its replacement is how an operator
        // changes a schedule without losing the old one's history.
        saveRule($product, ['weekday_mask' => WeekdayMask::fromDays([2]), 'is_active' => false]);

        $live = saveRule($product, ['weekday_mask' => WeekdayMask::fromDays([2])]);

        // And editing that rule must not find itself.
        $again = saveRule($product, ['start_time' => '09:30'], rule: $live);

        expect($again->getKey())->toBe($live->getKey())
            ->and(substr((string) $again->start_time, 0, 5))->toBe('09:30');
    });
})->group('fast');

it('lets two trips share a day and an hour', function (): void {
    scheduleTenant(function (): void {
        // A clash is about one trip's own timetable. Two trips leaving at nine
        // is an ordinary morning, and the boat's own calendar is what refuses
        // an overlapping sailing.
        $mine = saveRule(Product::factory()->create(), ['weekday_mask' => WeekdayMask::fromDays([2])]);
        $other = saveRule(Product::factory()->create(), ['weekday_mask' => WeekdayMask::fromDays([2])]);

        expect($mine->product_id)->not->toBe($other->product_id)
            ->and($other->exists)->toBeTrue();
    });
})->group('fast');

it('refuses a vessel that does not exist', function (): void {
    scheduleTenant(function (): void {
        saveRule(Product::factory()->create(), ['vessel_id' => 99999]);
    });
})->throws(ValidationException::class)->group('fast');

it('refuses another operator vessel without saying it exists', function (): void {
    // The global scope does the work: the boat is simply not found, and the
    // message says so rather than confirming another tenant owns it.
    $theirs = Tenancy::forTenant(
        Tenant::factory()->create(),
        fn (): Vessel => Vessel::factory()->create(),
    );

    scheduleTenant(function () use ($theirs): void {
        saveRule(Product::factory()->create(), ['vessel_id' => $theirs->getKey()]);
    });
})->throws(ValidationException::class)->group('fast');

it('inherits the product vessel when none is given', function (): void {
    scheduleTenant(function (): void {
        $vessel = Vessel::factory()->create();
        $product = Product::factory()->create(['vessel_id' => $vessel->getKey()]);

        expect(saveRule($product)->effectiveVesselId())->toBe($vessel->getKey());
    });
})->group('fast');

it('reports no dates at all for an inactive rule', function (): void {
    scheduleTenant(function (): void {
        // Not the same as an empty mask: the operator paused it deliberately,
        // and the rule keeps its days so switching it back on restores them.
        $rule = saveRule(Product::factory()->create(), ['is_active' => false]);

        expect($rule->nextDates(Carbon::parse('2026-06-01')))->toBe([])
            ->and($rule->coversDate(Carbon::parse('2026-06-02')))->toBeFalse()
            ->and($rule->weekday_mask)->toBe(WeekdayMask::DAILY);
    });
})->group('fast');

it('lists the next dates it would produce, inside the window', function (): void {
    scheduleTenant(function (): void {
        $rule = saveRule(Product::factory()->create(), [
            'weekday_mask' => WeekdayMask::fromDays([1]),
            'valid_from' => '2026-06-01',
            'valid_until' => '2026-06-30',
        ]);

        $dates = array_map(
            static fn (Carbon $d): string => $d->toDateString(),
            $rule->nextDates(Carbon::parse('2026-05-01'), 10),
        );

        // Starts at `valid_from` rather than at the date asked for, and stops
        // at `valid_until` rather than filling the ten.
        expect($dates)->toBe(['2026-06-01', '2026-06-08', '2026-06-15', '2026-06-22', '2026-06-29']);
    });
})->group('fast');
