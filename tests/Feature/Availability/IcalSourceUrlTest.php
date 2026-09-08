<?php

declare(strict_types=1);

use App\Models\IcalSource;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| §1.7: the source URL is a credential, and must be encrypted at rest
|--------------------------------------------------------------------------
|
| A private Airbnb or Google feed URL exposes an operator's whole calendar to
| anybody holding it. `docs/data-model.md` §1.7 names this column as encrypted
| and the model's docblock has said so since #29 — while the row was in fact
| written in plaintext, because a custom attribute mutator replaced the
| `encrypted` cast's setter and left its getter behind.
|
| Nothing caught it for two milestones because nothing ever read the column: the
| table was pulled forward into M1 and the sync that fetches the URL is M5. This
| file is the guard that would have.
|
| It asserts against the **raw row**, not the model. Reading through the model
| is what hid the problem in the first place — the round trip works whether or
| not anything was encrypted.
|
*/

/** @return array{0: Tenant, 1: IcalSource} */
function urlFixture(string $url = 'https://www.airbnb.com/calendar/ical/secret-91528.ics'): array
{
    $tenant = Tenant::factory()->create();

    $source = Tenancy::forTenant($tenant, function () use ($url): IcalSource {
        $vessel = Vessel::factory()->create();

        return IcalSource::factory()->create([
            'vessel_id' => $vessel->getKey(),
            'url' => $url,
        ]);
    });

    return [$tenant, $source];
}

it('writes the URL to the database encrypted, never in the clear', function (): void {
    $url = 'https://www.airbnb.com/calendar/ical/secret-91528.ics';

    [, $source] = urlFixture($url);

    $raw = DB::table('ical_sources')->where('id', $source->getKey())->value('url');

    expect($raw)->toBeString()
        // The whole point. A leaked database row must not hand somebody an
        // operator's entire calendar.
        ->and($raw)->not->toContain('airbnb.com')
        ->and($raw)->not->toContain('secret-91528')
        ->and($raw)->not->toBe($url);
});

it('reads the URL back as the plaintext that was written', function (): void {
    $url = 'https://www.airbnb.com/calendar/ical/secret-91528.ics';

    [$tenant, $source] = urlFixture($url);

    // The read path is what the sync uses. Before #124 this threw
    // `DecryptException`, because the getter was decrypting plaintext.
    $read = Tenancy::forTenant($tenant, fn (): string => IcalSource::query()
        ->findOrFail($source->getKey())
        ->url);

    expect($read)->toBe($url);
});

it('hashes the plaintext, so the same feed twice is caught by the index', function (): void {
    $url = 'https://www.airbnb.com/calendar/ical/secret-91528.ics';

    [, $source] = urlFixture($url);

    $hash = DB::table('ical_sources')->where('id', $source->getKey())->value('url_hash');

    // Hashed from the plaintext rather than the ciphertext: encryption is
    // randomised, so two rows holding the same URL have different bytes and a
    // hash of those would never collide — which is the entire job of the
    // unique index this column exists for.
    expect($hash)->toBe(hash('sha256', $url));
});

it('refuses the same feed added twice to one operator', function (): void {
    $url = 'https://www.airbnb.com/calendar/ical/secret-91528.ics';

    [$tenant, $source] = urlFixture($url);

    // The same URL again would double every imported event and make the boat
    // look busy for occupations that do not exist.
    Tenancy::forTenant($tenant, function () use ($source, $url): void {
        expect(fn () => IcalSource::factory()->create([
            'vessel_id' => $source->vessel_id,
            'url' => $url,
        ]))->toThrow(QueryException::class);
    });
});
