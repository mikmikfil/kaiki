<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\SaveSeason;
use App\Domain\Pricing\Support\SeasonCandidateResolver;
use App\Models\Season;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| PRC-4 — a priority tie, prevented and then survived
|--------------------------------------------------------------------------
|
| PRC-4 asks for **both** halves, and they protect against different things:
|
| - **Prevention at save time.** A tie is a configuration mistake an operator
|   can see and fix, and resolving it silently means their prices are decided by
|   a row id they never look at.
| - **Deterministic order at read time.** For the rows that got in another way —
|   an import, a direct edit, a row written before the validation existed.
|   *"The engine must never depend on database row order."*
|
| A suite with only the first would pass while the engine answered differently
| on MySQL and SQLite. A suite with only the second would let operators keep
| creating the mistake.
|
*/

function seasonScenario(callable $callback): mixed
{
    Queue::fake();
    Carbon::setTestNow('2026-01-15 08:00:00');

    $result = Tenancy::forTenant(Tenant::factory()->create(['timezone' => 'Europe/Athens']), $callback);

    Carbon::setTestNow();

    return $result;
}

it('PRC-4: a save that would create a priority tie is refused', function (): void {
    seasonScenario(function (): void {
        // The primary protection. The operator is told, rather than having
        // their prices decided by an id.
        app(SaveSeason::class)(new Season, [
            'name' => ['el' => 'Καλοκαίρι', 'en' => 'Summer'],
            'priority' => 10,
            'is_active' => true,
        ], [['starts_on' => '2026-06-01', 'ends_on' => '2026-09-15']]);

        app(SaveSeason::class)(new Season, [
            'name' => ['el' => 'Αύγουστος', 'en' => 'August'],
            'priority' => 10,
            'is_active' => true,
        ], [['starts_on' => '2026-08-01', 'ends_on' => '2026-08-31']]);
    });
})->throws(ValidationException::class)->group('fast');

it('PRC-4: higher priority wins outright', function (): void {
    seasonScenario(function (): void {
        // How "August" sits inside "Summer" — the ordinary, intended case.
        $summer = Season::factory()->priority(10)->withRange('2026-06-01', '2026-09-15')->create();
        $august = Season::factory()->priority(50)->withRange('2026-08-01', '2026-08-31')->create();

        $ordered = SeasonCandidateResolver::candidates(Carbon::parse('2026-08-10'));

        expect($ordered->first()?->getKey())->toBe($august->getKey())
            ->and($ordered->last()?->getKey())->toBe($summer->getKey());
    });
})->group('fast');

it('PRC-4: a tie that got in anyway resolves by narrowest matching range', function (): void {
    seasonScenario(function (): void {
        // Written past the Action, which is what an import does. The narrower
        // statement is the more specific one, and that is what an operator
        // means by writing it.
        $wide = Season::factory()->priority(10)->withRange('2026-06-01', '2026-09-15')->create();
        $narrow = Season::factory()->priority(10)->withRange('2026-08-01', '2026-08-31')->create();

        $ordered = SeasonCandidateResolver::candidates(Carbon::parse('2026-08-10'));

        expect($ordered->first()?->getKey())->toBe($narrow->getKey())
            ->and($ordered->last()?->getKey())->toBe($wide->getKey());
    });
})->group('fast');

it('PRC-4: an exact tie on width resolves by id, and never by row order', function (): void {
    seasonScenario(function (): void {
        // The last resort. Both seasons cover the same fortnight at the same
        // priority — nothing distinguishes them but the id, and the engine must
        // still answer the same way twice.
        $first = Season::factory()->priority(10)->withRange('2026-08-01', '2026-08-14')->create();
        $second = Season::factory()->priority(10)->withRange('2026-08-01', '2026-08-14')->create();

        $once = SeasonCandidateResolver::candidates(Carbon::parse('2026-08-10'))->pluck('id')->all();
        $twice = SeasonCandidateResolver::candidates(Carbon::parse('2026-08-10'))->pluck('id')->all();

        expect($once)->toBe([$first->getKey(), $second->getKey()])
            ->and($twice)->toBe($once);
    });
})->group('fast');

it('PRC-4: an inactive season never enters the ordering', function (): void {
    seasonScenario(function (): void {
        Season::factory()->priority(50)->inactive()->withRange('2026-08-01', '2026-08-31')->create();
        $summer = Season::factory()->priority(10)->withRange('2026-06-01', '2026-09-15')->create();

        expect(SeasonCandidateResolver::candidates(Carbon::parse('2026-08-10'))->pluck('id')->all())
            ->toBe([$summer->getKey()]);
    });
})->group('fast');

it('PRC-4: the one-query loader orders identically to the per-date one', function (): void {
    seasonScenario(function (): void {
        // #30 added `allWithRanges()` for the five-query budget. Two loaders
        // that disagreed about ordering would price the same date differently
        // depending on which caller asked.
        Season::factory()->priority(10)->withRange('2026-06-01', '2026-09-15')->create();
        Season::factory()->priority(50)->withRange('2026-08-01', '2026-08-31')->create();

        $date = Carbon::parse('2026-08-10');

        $queried = SeasonCandidateResolver::candidates($date)->pluck('id')->all();

        $preloaded = SeasonCandidateResolver::order(
            SeasonCandidateResolver::allWithRanges()->filter(fn (Season $season): bool => $season->contains($date)),
            $date,
        )->pluck('id')->all();

        expect($preloaded)->toBe($queried);
    });
})->group('fast');
