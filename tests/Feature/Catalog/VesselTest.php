<?php

declare(strict_types=1);

use App\Enums\VesselStatus;
use App\Enums\VesselType;
use App\Exceptions\CapacityLoweringRefused;
use App\Models\Port;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Database\QueryException;
use Tests\Support\Catalog\FakeCapacityClaims;

/*
|--------------------------------------------------------------------------
| Vessels — spec CAT-1, CAT-2, TEN-6, AVL-7
|--------------------------------------------------------------------------
|
| The vessel is the bookable resource: nothing in the product is sellable
| without a free vessel window (AVL-1). Two things here are load-bearing far
| beyond this file — the turnaround buffer, which every availability conflict
| check reads, and the capacity ceiling, which is a legal limit rather than a
| business preference.
|
*/

function forVessel(callable $callback): mixed
{
    return Tenancy::forTenant(Tenant::factory()->create(), $callback);
}

it('inherits the tenant turnaround buffer when the vessel sets none', function (): void {
    // AVL-7's default, and the case the column is nullable *for*. A vessel that
    // copied 60 onto itself would make the account setting decorative — the
    // operator would change it and nothing would move.
    $tenant = Tenant::factory()->create(['turnaround_buffer_minutes' => 45]);

    $vessel = Tenancy::forTenant($tenant, fn (): Vessel => Vessel::factory()->create());

    expect($vessel->turnaround_buffer_minutes)->toBeNull()
        ->and($vessel->inheritsTurnaroundBuffer())->toBeTrue()
        ->and($vessel->effectiveTurnaroundBufferMinutes())->toBe(45);
})->group('fast');

it('uses the vessel turnaround buffer when it sets its own', function (): void {
    $tenant = Tenant::factory()->create(['turnaround_buffer_minutes' => 45]);

    $vessel = Tenancy::forTenant($tenant, fn (): Vessel => Vessel::factory()->withBuffer(90)->create());

    expect($vessel->inheritsTurnaroundBuffer())->toBeFalse()
        ->and($vessel->effectiveTurnaroundBufferMinutes())->toBe(90);
})->group('fast');

it('follows the tenant default when it changes, for an inheriting vessel', function (): void {
    // The whole reason the column is nullable rather than defaulted. If this
    // fails, AVL-7's inheritance exists in the schema and not in behaviour.
    $tenant = Tenant::factory()->create(['turnaround_buffer_minutes' => 60]);

    $vessel = Tenancy::forTenant($tenant, fn (): Vessel => Vessel::factory()->create());

    $tenant->update(['turnaround_buffer_minutes' => 30]);

    expect($vessel->fresh()?->effectiveTurnaroundBufferMinutes())->toBe(30);
})->group('fast');

it('resolves the buffer without a resolved tenant context', function (): void {
    // A console command iterating operators, or the nightly reconciler, reads
    // vessels outside any tenant. The accessor must still answer rather than
    // silently returning the fixed default.
    $tenant = Tenant::factory()->create(['turnaround_buffer_minutes' => 75]);

    $vessel = Tenancy::forTenant($tenant, fn (): Vessel => Vessel::factory()->create());

    $resolved = Tenancy::withoutTenancy(
        fn (): int => Vessel::query()->withoutGlobalScopes()->findOrFail($vessel->getKey())
            ->effectiveTurnaroundBufferMinutes(),
    );

    expect($resolved)->toBe(75);
})->group('fast');

it('refuses to lower capacity_max below a record that already promises more', function (): void {
    // AC 4. `products` and `departures` do not exist yet, so the claim source
    // is a fake — but the Action, the message and the observer are the real
    // ones, which is the part that has to be right before #18 relies on it.
    FakeCapacityClaims::register([
        ['kind' => 'departure', 'label' => 'Sat 14 Jun, 10:00', 'pax' => 30],
    ]);

    forVessel(function (): void {
        $vessel = Vessel::factory()->capacity(40)->create();

        expect(fn () => $vessel->update(['capacity_max' => 20]))
            ->toThrow(CapacityLoweringRefused::class);
    });
})->group('fast');

it('lists the offending records in the refusal, in the operator language', function (): void {
    // CNV-11: the message exists in Greek and English and comes from a lang
    // file. "You cannot do that", with no list, leaves the operator guessing
    // which of forty departures is the one in the way.
    FakeCapacityClaims::register([
        ['kind' => 'departure', 'label' => 'Σάββατο 14 Ιουν, 10:00', 'pax' => 30],
        ['kind' => 'product', 'label' => 'Ημερήσια κρουαζιέρα', 'pax' => 36],
    ]);

    app()->setLocale('el');

    forVessel(function (): void {
        $vessel = Vessel::factory()->capacity(40)->create();

        try {
            $vessel->update(['capacity_max' => 20]);

            throw new RuntimeException('The save should have been refused.');
        } catch (CapacityLoweringRefused $refusal) {
            expect($refusal->getMessage())->toContain('Ημερήσια κρουαζιέρα (36)');
            expect($refusal->getMessage())->toContain('Σάββατο 14 Ιουν, 10:00 (30)');

            // Not the untranslated key. That is what a missing lang line renders
            // as, and an assertion on "contains the label" alone would accept it
            // happily — the labels come from the claim, not from the lang file.
            expect($refusal->getMessage())->not->toContain('catalog.vessel.capacity.refused');

            // Biggest offender first: it is the one that decides the floor.
            expect($refusal->claims[0]->pax)->toBe(36);
            expect($refusal->requestedCapacity)->toBe(20);
        }
    });
})->group('fast');

it('allows raising capacity_max even when records claim seats', function (): void {
    FakeCapacityClaims::register([
        ['kind' => 'departure', 'label' => 'Sat 14 Jun, 10:00', 'pax' => 30],
    ]);

    forVessel(function (): void {
        $vessel = Vessel::factory()->capacity(40)->create();

        $vessel->update(['capacity_max' => 60]);

        expect($vessel->fresh()?->capacity_max)->toBe(60);
    });
})->group('fast');

it('allows lowering capacity_max to a value nothing exceeds', function (): void {
    FakeCapacityClaims::register([
        ['kind' => 'departure', 'label' => 'Sat 14 Jun, 10:00', 'pax' => 12],
    ]);

    forVessel(function (): void {
        $vessel = Vessel::factory()->capacity(40)->create();

        $vessel->update(['capacity_max' => 12]);

        // Exactly equal is allowed: `capacity_max` is a ceiling, and a
        // departure of twelve on a boat for twelve is a full boat, not an
        // oversold one.
        expect($vessel->fresh()?->capacity_max)->toBe(12);
    });
})->group('fast');

it('does not consult claim sources when capacity_max is untouched', function (): void {
    // Every unrelated vessel edit would otherwise pay for a fan-out across
    // products and departures. The fake would refuse if it were asked.
    FakeCapacityClaims::register([
        ['kind' => 'departure', 'label' => 'Sat 14 Jun, 10:00', 'pax' => 999],
    ]);

    forVessel(function (): void {
        $vessel = Vessel::factory()->capacity(40)->create();

        $vessel->update(['captain_name' => 'Γιώργος Δημητρίου']);

        expect($vessel->fresh()?->captain_name)->toBe('Γιώργος Δημητρίου');
    });
})->group('fast');

it('refuses nothing while no claim source is registered', function (): void {
    // The state the application actually ships in until #18. A guard that
    // refused anything here would block every operator lowering a capacity for
    // an ordinary reason.
    forVessel(function (): void {
        $vessel = Vessel::factory()->capacity(40)->create();

        $vessel->update(['capacity_max' => 2]);

        expect($vessel->fresh()?->capacity_max)->toBe(2);
    });
})->group('fast');

it('folds the vessel name into a sort key and the search haystack', function (): void {
    // The reason `name` gets companion columns despite not being translatable:
    // MySQL folds Greek tonos when comparing and SQLite does not, so a raw
    // `like` returns different rows on the two engines.
    forVessel(function (): void {
        $vessel = Vessel::factory()->named('Οδυσσεύς')->create();

        expect($vessel->name_sort)->toBe('οδυσσευσ')
            ->and((string) $vessel->search_index)->toContain('οδυσσευσ');
    });
})->group('fast');

it('finds a vessel by an unaccented search term', function (): void {
    forVessel(function (): void {
        Vessel::factory()->named('Οδυσσεύς')->create();
        Vessel::factory()->named('Ποσειδών')->create();

        expect(Vessel::query()->whereTranslationMatches('οδυσσευσ')->count())->toBe(1)
            ->and(Vessel::query()->whereTranslationMatches('ΟΔΥΣΣΕΥΣ')->count())->toBe(1)
            // An empty box shows everything rather than nothing.
            ->and(Vessel::query()->whereTranslationMatches('')->count())->toBe(2);
    });
})->group('fast');

it('finds a vessel by its translated description as well as its name', function (): void {
    // One haystack for both, because an operator searching their fleet does not
    // know or care which field a word lives in.
    forVessel(function (): void {
        Vessel::factory()->named('Γαλήνη')->create([
            'description' => ['el' => 'Ταχύπλοο φουσκωτό', 'en' => 'A fast RIB'],
        ]);

        expect(Vessel::query()->whereTranslationMatches('φουσκωτο')->count())->toBe(1)
            ->and(Vessel::query()->whereTranslationMatches('fast rib')->count())->toBe(1);
    });
})->group('fast');

it('orders vessels by the folded name rather than the raw column', function (): void {
    forVessel(function (): void {
        Vessel::factory()->named('Ώρα')->create();
        Vessel::factory()->named('Άλφα')->create();
        Vessel::factory()->named('Βήτα')->create();

        expect(Vessel::query()->orderByFolded('name')->pluck('name')->all())
            ->toBe(['Άλφα', 'Βήτα', 'Ώρα']);
    });
})->group('fast');

it('refuses to order by an attribute that has no folded companion column', function (): void {
    // Filament passes the sort column straight from the query string, so "it is
    // only ever called with a constant" stops being true the moment this is
    // wired to a table header.
    forVessel(function (): void {
        expect(fn () => Vessel::query()->orderByFolded('captain_name')->get())
            ->toThrow(InvalidArgumentException::class, 'not a sortable folded attribute');
    });
})->group('fast');

it('enforces vessel name uniqueness per tenant, not globally', function (): void {
    // TEN-6. Two operators may both have a boat called Οδυσσέας; one operator
    // may not have two.
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    Tenancy::forTenant($a, fn (): Vessel => Vessel::factory()->named('Οδυσσέας')->create());
    Tenancy::forTenant($b, fn (): Vessel => Vessel::factory()->named('Οδυσσέας')->create());

    expect(Tenancy::withoutTenancy(fn (): int => Vessel::query()->withoutGlobalScopes()->count()))->toBe(2);

    Tenancy::forTenant($a, function (): void {
        expect(fn () => Vessel::factory()->named('Οδυσσέας')->create())
            ->toThrow(QueryException::class);
    });
})->group('fast');

it('keeps a soft-deleted vessel name reserved until it is force-deleted', function (): void {
    // The trade-off behind leaving `deleted_at` out of the unique key. Putting
    // it in would not free the name — it would disable the constraint for live
    // rows, because NULL never equals NULL in a unique index on either engine.
    //
    // Reserving it is also the safer half of the trade: a soft-deleted vessel
    // can be restored, and restoring one into a name collision is a worse
    // failure than refusing the duplicate now.
    forVessel(function (): void {
        $first = Vessel::factory()->named('Οδυσσέας')->create();
        $first->delete();

        expect(fn () => Vessel::factory()->named('Οδυσσέας')->create())
            ->toThrow(QueryException::class);

        $first->forceDelete();

        expect(Vessel::factory()->named('Οδυσσέας')->create()->exists)->toBeTrue();
    });
})->group('fast');

it('soft-deletes rather than removing the row', function (): void {
    forVessel(function (): void {
        $vessel = Vessel::factory()->create();
        $vessel->delete();

        expect(Vessel::query()->count())->toBe(0)
            ->and(Vessel::withTrashed()->count())->toBe(1)
            ->and(Vessel::withTrashed()->first()?->trashed())->toBeTrue();
    });
})->group('fast');

it('keeps the vessel when its home port is deleted', function (): void {
    // `nullOnDelete`, deliberately: an operator tidying their marina list must
    // not lose the fleet based there.
    forVessel(function (): void {
        $port = Port::factory()->create();
        $vessel = Vessel::factory()->atPort($port)->create();

        $port->forceDelete();

        expect($vessel->fresh())->not->toBeNull()
            ->and($vessel->fresh()?->home_port_id)->toBeNull();
    });
})->group('fast');

it('casts type and status to their enums', function (): void {
    forVessel(function (): void {
        $vessel = Vessel::factory()->inMaintenance()->create();

        expect($vessel->status)->toBeInstanceOf(VesselStatus::class)
            ->and($vessel->status->isSellable())->toBeFalse()
            ->and($vessel->fresh()?->type)->toBeInstanceOf(VesselType::class);
    });
})->group('fast');
