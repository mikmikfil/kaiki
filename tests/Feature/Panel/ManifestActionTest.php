<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\BookingStatus;
use App\Enums\ManifestColumn;
use App\Enums\Role;
use App\Filament\App\Resources\DepartureResource\Pages\ListDepartures;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\Departure;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| OPS-10, GDR-6: two capabilities, and they are not the same question
|--------------------------------------------------------------------------
|
| `ViewManifest` says whether somebody may have a passenger list at all — crew
| have it, because standing on the quay with one is their job. `ViewGuestDocuments`
| says whether they may have the *numbers*, which is narrower.
|
| The distinction has to be enforced on the **form**, not only on the output. A
| disabled checkbox labelled "document number" still tells somebody the data is
| there and that they are the person not allowed to see it.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    Carbon::setTestNow('2026-07-08 06:00:00');
});

function departureListAs(User $user): Testable
{
    tenancy()->initialize($user->tenant);

    return Livewire::actingAs($user)->test(ListDepartures::class);
}

function sailingWithGuests(User $user): Departure
{
    return Tenancy::forTenant($user->tenant, function (): Departure {
        $sailing = Departure::factory()->at('2026-07-09', '09:00')->withSeats(2)->create();

        $booking = Booking::factory()->create([
            'departure_id' => $sailing->getKey(),
            'status' => BookingStatus::Confirmed,
            'pax_total' => 1,
        ]);

        BookingGuest::factory()->create([
            'booking_id' => $booking->getKey(),
            'position' => 1,
            'full_name' => 'Anna Rossi',
            'document_number' => 'AA1234567',
        ]);

        return $sailing;
    });
}

it('offers the manifest to an owner, with every column', function (): void {
    $user = OperatorUser::withRole(Role::Owner);

    $departure = sailingWithGuests($user);

    departureListAs($user)
        ->assertTableActionVisible('manifest', $departure)
        ->mountTableAction('manifest', $departure)
        ->assertSee(ManifestColumn::DocumentNumber->label())
        // OPS-10's warning, before the download rather than after it. Telling
        // somebody afterwards that their name is attached to a list of passport
        // numbers is not consent.
        ->assertSee(__('manifest.action.sensitive'));
});

it('offers crew the list and not the numbers', function (): void {
    $crew = OperatorUser::withRole(Role::Crew);

    $departure = sailingWithGuests($crew);

    expect(Tenancy::forTenant($crew->tenant, fn (): bool => $crew->hasCapability(Capability::ViewManifest)))->toBeTrue()
        ->and(Tenancy::forTenant($crew->tenant, fn (): bool => $crew->hasCapability(Capability::ViewGuestDocuments)))->toBeFalse();

    departureListAs($crew)
        ->assertTableActionVisible('manifest', $departure)
        ->mountTableAction('manifest', $departure)
        ->assertSee(ManifestColumn::FullName->label())
        // Absent from the form entirely. A disabled checkbox still tells them
        // the data is there.
        ->assertDontSee(ManifestColumn::DocumentNumber->label())
        ->assertDontSee(__('manifest.action.sensitive'));
});

it('records the download through the panel, not only through the action class', function (): void {
    // The CSV's own contents are proven in `ManifestTest`. What this proves is
    // that the *panel* path goes through `GenerateManifest` — a screen that
    // built the file itself would produce the same download and no audit row,
    // and GDR-6's claim would quietly stop being true.
    $user = OperatorUser::withRole(Role::Owner);

    $departure = sailingWithGuests($user);

    departureListAs($user)
        ->callTableAction('manifest', $departure, [
            'columns' => [ManifestColumn::FullName->value, ManifestColumn::DocumentNumber->value],
            'layout' => 'standard',
            'format' => 'csv',
        ])
        ->assertHasNoTableActionErrors();

    $entry = Tenancy::forTenant(
        $user->tenant,
        fn (): ?AuditLog => AuditLog::query()->where('action', AuditAction::ManifestGenerated->value)->first(),
    );

    expect($entry)->not->toBeNull()
        ->and($entry?->user_id)->toBe($user->getKey());
});

/*
 * **The "no document numbers, no audit row" case is not asserted here**, and
 * that is a limitation of the test helper rather than a gap.
 *
 * Both `callTableAction($name, $record, $data)` and `setTableActionData()`
 * merge into the mounted form's state index by index, so a two-item selection
 * over a five-item default leaves items three to five — including the document
 * number — in place. In a browser, unticking a box removes it. Contorting the
 * production code to make the helper expressive would be optimising for the
 * test.
 *
 * The guarantee itself is asserted where it lives, on the action that decides:
 * `ManifestTest` → *"it logs the generation when it carries document numbers,
 * and not when it does not"*.
 */

it('never falls back to a set that includes document numbers', function (): void {
    // The fallback in `fromValues` is the one that must not be the convenient
    // one: an empty selection becoming the *default* set would put passport
    // numbers on a manifest nobody asked for them on, and no screen would say
    // so.
    expect(ManifestColumn::fromValues([]))->toBe([ManifestColumn::FullName])
        ->and(ManifestColumn::anySensitive(ManifestColumn::fromValues([])))->toBeFalse();
});
