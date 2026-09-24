<?php

declare(strict_types=1);

use App\Domain\Availability\Actions\AssignDepartureCrew;
use App\Domain\Operations\Support\Manifest;
use App\Enums\ManifestColumn;
use App\Enums\Role;
use App\Models\Departure;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Validation\ValidationException;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| Captain and crew per departure — the 24/9 list, #3
|--------------------------------------------------------------------------
|
| The boat's `captain_name` is its usual skipper; today's may be someone else,
| with or without an account. The passenger list for the Λιμεναρχείο names
| today's captain and crew, and falls back to the boat's when nobody is set.
|
*/

it('prints the departure\'s captain and crew, and the boat\'s captain when none is set', function (): void {
    $captain = OperatorUser::withRole(Role::Crew);
    $deckhand = OperatorUser::withRole(Role::Crew, $captain->tenant);
    $tenant = Tenant::query()->findOrFail($captain->tenant_id);

    Tenancy::forTenant($tenant, function () use ($captain, $deckhand): void {
        $vessel = Vessel::factory()->create(['captain_name' => 'Συνήθης Κυβερνήτης']);
        $departure = Departure::factory()->for($vessel)->at('2026-07-09', '09:00')->create();

        expect($departure->captainName())->toBe('Συνήθης Κυβερνήτης');

        app(AssignDepartureCrew::class)($departure, $captain->getKey(), 'Αγνοείται', [$deckhand->getKey(), $captain->getKey()]);

        $departure->refresh();
        $manifest = Manifest::forDeparture($departure, [ManifestColumn::FullName]);

        expect($departure->captain_name)->toBeNull()
            // The captain is not listed twice.
            ->and($departure->crew_user_ids)->toBe([(int) $deckhand->getKey()])
            ->and($manifest->header['captain'])->toBe($captain->name)
            ->and($manifest->header['crew'])->toBe($deckhand->name);

        // A freelance skipper with no account: the typed name.
        app(AssignDepartureCrew::class)($departure, null, 'Νίκος Ελεύθερος', []);

        expect($departure->refresh()->captainName())->toBe('Νίκος Ελεύθερος')
            ->and($departure->crew_user_ids)->toBeNull();
    });
})->group('fast');

it('refuses somebody from another operator', function (): void {
    $ours = OperatorUser::withRole(Role::Crew);
    $theirs = OperatorUser::withRole(Role::Crew);
    $tenant = Tenant::query()->findOrFail($ours->tenant_id);

    Tenancy::forTenant($tenant, function () use ($theirs): void {
        $departure = Departure::factory()->at('2026-07-09', '09:00')->create();

        expect(fn () => app(AssignDepartureCrew::class)($departure, $theirs->getKey(), null, []))
            ->toThrow(ValidationException::class);

        expect($departure->refresh()->captain_user_id)->toBeNull();
    });
})->group('fast');
