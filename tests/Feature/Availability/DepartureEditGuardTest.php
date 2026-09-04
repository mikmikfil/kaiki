<?php

declare(strict_types=1);

use App\Domain\Availability\Actions\UpdateDeparture;
use App\Models\Departure;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Editing a departure someone has paid for — spec AVL-52, AVL-21
|--------------------------------------------------------------------------
|
| Three refusals, and every one of them is about a guest who has already
| booked. Moving a sold departure changes what they turn up to without telling
| them; the ticket, the calendar entry and the reminder went out with the old
| time. Lowering capacity below the sold count is not a smaller boat, it is a
| number that makes the availability arithmetic negative.
|
| The remedy in each case is to cancel and rebook, which is visible to the
| guest. That is the whole argument.
|
*/

function editTenant(callable $callback): mixed
{
    Queue::fake();

    return Tenancy::forTenant(Tenant::factory()->create(['timezone' => 'Europe/Athens']), $callback);
}

/** @param array<string, mixed> $attributes */
function update(Departure $departure, array $attributes): Departure
{
    return app(UpdateDeparture::class)($departure, $attributes);
}

it('lets an operator edit an unsold departure freely', function (): void {
    editTenant(function (): void {
        $departure = Departure::factory()->at('2026-07-04', '09:00')->create(['capacity' => 12]);

        $updated = update($departure, ['capacity' => 8, 'notes' => 'Χαμηλή ζήτηση']);

        expect($updated->capacity)->toBe(8)
            ->and($updated->notes)->toBe('Χαμηλή ζήτηση');
    });
})->group('fast');

it('refuses to lower capacity below the seats already sold', function (): void {
    editTenant(function (): void {
        $departure = Departure::factory()->withSeats(sold: 10)->create(['capacity' => 12]);

        update($departure, ['capacity' => 8]);
    });
})->throws(ValidationException::class)->group('fast');

it('allows capacity down to exactly the sold count', function (): void {
    editTenant(function (): void {
        // Not an off-by-one to be nervous about: ten sold and ten seats is a
        // full boat, which is an ordinary thing for an operator to record.
        $departure = Departure::factory()->withSeats(sold: 10)->create(['capacity' => 12]);

        expect(update($departure, ['capacity' => 10])->capacity)->toBe(10);
    });
})->group('fast');

it('refuses to move the date of a sold departure', function (): void {
    editTenant(function (): void {
        $departure = Departure::factory()->at('2026-07-04', '09:00')->withSeats(sold: 2)->create();

        update($departure, ['local_date' => '2026-07-05']);
    });
})->throws(ValidationException::class)->group('fast');

it('refuses to move the time of a sold departure', function (): void {
    editTenant(function (): void {
        $departure = Departure::factory()->at('2026-07-04', '09:00')->withSeats(sold: 2)->create();

        update($departure, ['local_time' => '10:00']);
    });
})->throws(ValidationException::class)->group('fast');

it('refuses to move a departure that only has seats held', function (): void {
    editTenant(function (): void {
        // A hold is somebody sitting on the payment page. Moving the departure
        // underneath them is the same failure as moving a sold one, arriving a
        // few minutes earlier.
        $departure = Departure::factory()->at('2026-07-04', '09:00')->withSeats(sold: 0, held: 2)->create();

        update($departure, ['local_time' => '10:00']);
    });
})->throws(ValidationException::class)->group('fast');

it('accepts an unchanged date and time on a sold departure', function (): void {
    editTenant(function (): void {
        // The form posts every field, so a note edit arrives carrying the same
        // date and time. Comparing values rather than presence is what keeps
        // that from reading as a move.
        $departure = Departure::factory()->at('2026-07-04', '09:00')->withSeats(sold: 2)->create();

        $updated = update($departure, [
            'local_date' => '2026-07-04',
            // `HH:MM` from a form against `HH:MM:SS` from the database.
            'local_time' => '09:00',
            'notes' => 'Ο καπετάνιος ενημερώθηκε',
        ]);

        expect($updated->notes)->toBe('Ο καπετάνιος ενημερώθηκε');
    });
})->group('fast');

it('refuses to repoint a departure at another product', function (): void {
    editTenant(function (): void {
        // The departure is the sellable instance *of* a product. Repointing it
        // would re-price every booking on it retroactively.
        $departure = Departure::factory()->create();

        update($departure, ['product_id' => Product::factory()->create()->getKey()]);
    });
})->throws(ValidationException::class)->group('fast');

it('never writes the time triple half way', function (): void {
    editTenant(function (): void {
        // CNV-3's guard refuses a row whose local pair and UTC instant
        // disagree, which is the correct outcome and a confusing one to debug.
        // The Action only fills the fields it is allowed to, so a stray
        // `local_time` in the payload cannot produce one.
        $departure = Departure::factory()->at('2026-07-04', '09:00')->create();

        $updated = update($departure, ['local_time' => '11:00', 'notes' => 'x']);

        expect($updated->local_time)->toBe('09:00:00')
            ->and($updated->starts_at_utc->toDateTimeString())->toBe('2026-07-04 06:00:00');
    });
})->group('fast');
