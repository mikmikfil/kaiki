<?php

declare(strict_types=1);

use App\Exceptions\MissingTranslationException;
use App\Models\Port;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;

/*
|--------------------------------------------------------------------------
| Ports and meeting points — spec CAT-3, CAT-6, I18N-4
|--------------------------------------------------------------------------
|
| One table serving two roles (`docs/data-model.md` §2.3): a product's meeting
| point and a vessel's home port are the same marina, and operators reuse them.
|
| This is the first table in the schema whose *name* is translatable, so it is
| also where ADR-0008 stops being infrastructure and starts being a screen an
| operator uses.
|
*/

function forPort(callable $callback): mixed
{
    return Tenancy::forTenant(Tenant::factory()->create(), $callback);
}

it('stores the name and instructions per locale', function (): void {
    forPort(function (): void {
        $port = Port::factory()->create([
            'name' => ['el' => 'Μαρίνα Ζέας', 'en' => 'Zea Marina'],
        ]);

        app()->setLocale('el');
        expect($port->name)->toBe('Μαρίνα Ζέας');

        app()->setLocale('en');
        expect($port->fresh()?->name)->toBe('Zea Marina');
    });
})->group('fast');

it('refuses to save a port whose name has only one locale', function (): void {
    // AC 7, through the rule from #15. §1.6 requires both keys on write, and
    // the I18N-5 fallback is what makes the omission invisible otherwise — an
    // English page rendering the Greek name looks *almost* right, so nobody
    // reports it.
    forPort(function (): void {
        expect(fn () => Port::factory()->create(['name' => ['el' => 'Μαρίνα Ζέας']]))
            ->toThrow(MissingTranslationException::class);
    });
})->group('fast', 'i18n');

it('names the missing locale when it refuses', function (): void {
    forPort(function (): void {
        expect(fn () => Port::factory()->create(['name' => ['en' => 'Zea Marina']]))
            ->toThrow(MissingTranslationException::class, '[App\Models\Port::$name] has no translation for [el]');
    });
})->group('fast', 'i18n');

it('treats a whitespace-only name as missing', function (): void {
    forPort(function (): void {
        expect(fn () => Port::factory()->create(['name' => ['el' => 'Μαρίνα Ζέας', 'en' => '   ']]))
            ->toThrow(MissingTranslationException::class);
    });
})->group('fast', 'i18n');

it('does not require instructions in either locale', function (): void {
    // A marina everyone already knows how to find needs no written directions,
    // and requiring them would block the operator from saving it at all.
    forPort(function (): void {
        $port = Port::factory()->create(['instructions' => null]);

        expect($port->exists)->toBeTrue();
    });
})->group('fast', 'i18n');

it('builds a search haystack from every locale of both fields', function (): void {
    forPort(function (): void {
        $port = Port::factory()->create([
            'name' => ['el' => 'Μαρίνα Ζέας', 'en' => 'Zea Marina'],
            'instructions' => ['el' => 'Στο μπλε περίπτερο', 'en' => 'At the blue kiosk'],
        ]);

        expect((string) $port->search_index)
            ->toContain('μαρινα ζεασ')
            ->toContain('zea marina')
            ->toContain('μπλε περιπτερο')
            ->toContain('blue kiosk');
    });
})->group('fast', 'i18n');

it('finds a port by an unaccented term in either language', function (): void {
    // A guest — and an operator — does not know which language a row was
    // written in, so one haystack answers for both.
    forPort(function (): void {
        Port::factory()->named(['el' => 'Μαρίνα Ζέας', 'en' => 'Zea Marina'])->create();
        Port::factory()->named(['el' => 'Λιμάνι Πειραιά', 'en' => 'Port of Piraeus'])->create();

        expect(Port::query()->whereTranslationMatches('ζεασ')->count())->toBe(1)
            ->and(Port::query()->whereTranslationMatches('zea')->count())->toBe(1)
            ->and(Port::query()->whereTranslationMatches('πειραια')->count())->toBe(1);
    });
})->group('fast', 'i18n');

it('orders ports per locale through the companion columns', function (): void {
    // Ordering *is* a per-language question: the same two rows read in a
    // different order in Greek than in English, and both orders are correct.
    forPort(function (): void {
        Port::factory()->named(['el' => 'Ύδρα', 'en' => 'Aegina'])->create();
        Port::factory()->named(['el' => 'Αίγινα', 'en' => 'Hydra'])->create();

        app()->setLocale('el');
        expect(Port::query()->orderByTranslation('name', 'asc', 'el')->pluck('name_sort_el')->all())
            ->toBe(['αιγινα', 'υδρα']);

        expect(Port::query()->orderByTranslation('name', 'asc', 'en')->pluck('name_sort_en')->all())
            ->toBe(['aegina', 'hydra']);
    });
})->group('fast', 'i18n');

it('round-trips latitude and longitude at seven decimal places', function (): void {
    // The only decimal columns in the schema. They exist because a float
    // drifts, and a drifting coordinate puts the meeting-point pin in the wrong
    // basin — which the guest discovers on the quay.
    forPort(function (): void {
        $port = Port::factory()->create(['lat' => '37.9339123', 'lng' => '23.6469876']);

        expect($port->fresh()?->lat)->toBe('37.9339123')
            ->and($port->fresh()?->lng)->toBe('23.6469876');
    });
})->group('fast');

it('prefers the operator maps_url over the coordinates', function (): void {
    // Some marinas resolve badly from a postal address and the operator knows
    // the pin that works. Their override has to win, or the field is decorative.
    forPort(function (): void {
        $port = Port::factory()->create([
            'lat' => '37.9339000',
            'lng' => '23.6469000',
            'maps_url' => 'https://maps.app.goo.gl/kaiki',
        ]);

        expect($port->mapsUrl())->toBe('https://maps.app.goo.gl/kaiki');
    });
})->group('fast');

it('falls back from maps_url to coordinates and then to the address', function (): void {
    forPort(function (): void {
        $pinned = Port::factory()->create(['lat' => '37.9339000', 'lng' => '23.6469000', 'maps_url' => null]);
        expect($pinned->mapsUrl())->toContain('37.9339000%2C23.6469000');

        $addressed = Port::factory()->withoutCoordinates()->create([
            'maps_url' => null,
            'address' => 'Ακτή Θεμιστοκλέους, Πειραιάς',
        ]);
        expect($addressed->mapsUrl())->toContain(rawurlencode('Ακτή Θεμιστοκλέους, Πειραιάς'));

        // Nothing to link to at all — the caller hides the button rather than
        // rendering a map search for the empty string.
        $bare = Port::factory()->withoutCoordinates()->create(['maps_url' => null, 'address' => null]);
        expect($bare->mapsUrl())->toBeNull();
    });
})->group('fast');

it('soft-deletes a port and leaves the vessels based there alone', function (): void {
    forPort(function (): void {
        $port = Port::factory()->create();
        $vessel = Vessel::factory()->atPort($port)->create();

        $port->delete();

        expect(Port::query()->count())->toBe(0)
            ->and(Port::withTrashed()->count())->toBe(1)
            // Soft delete is not a database delete, so the FK never fires and
            // the boat keeps pointing at a port it can be restored to.
            ->and($vessel->fresh()?->home_port_id)->toBe($port->getKey());
    });
})->group('fast');

it('counts the vessels based at a port', function (): void {
    forPort(function (): void {
        $port = Port::factory()->create();
        Vessel::factory()->count(2)->atPort($port)->create();
        Vessel::factory()->create();

        expect($port->vessels()->count())->toBe(2);
    });
})->group('fast');
