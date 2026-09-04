<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\SaveSeason;
use App\Domain\Pricing\Support\SeasonCandidateResolver;
use App\Enums\Role;
use App\Models\Season;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| Seasons — spec CAT-9, PRC-3, PRC-4, TEN-6
|--------------------------------------------------------------------------
|
| Ranges may overlap **across** seasons — that is the entire point of
| `priority`, and it is how "August" sits inside "Summer". What PRC-4 refuses is
| an overlap where the priorities are **equal**, because then nothing in the
| operator's own configuration decides which price applies.
|
| Prevented at save rather than resolved at read, and the difference matters: a
| tie resolved silently means an operator's prices are decided by a row id they
| never see, and the first they hear of it is a guest quoting another figure.
|
*/

function seasonTenant(callable $callback): mixed
{
    return Tenancy::forTenant(Tenant::factory()->create(), $callback);
}

/**
 * @param  array<string, mixed>  $attributes
 * @param  list<array{starts_on: string, ends_on: string}>  $ranges
 */
function saveSeason(array $attributes, array $ranges): Season
{
    return app(SaveSeason::class)(new Season, array_merge([
        'name' => ['el' => 'Υψηλή', 'en' => 'High'],
        'priority' => 10,
        'is_active' => true,
    ], $attributes), $ranges);
}

it('saves a season with its ranges', function (): void {
    seasonTenant(function (): void {
        $season = saveSeason(['code' => 'HIGH26'], [
            ['starts_on' => '2026-06-01', 'ends_on' => '2026-09-15'],
        ]);

        expect($season->dateRanges()->count())->toBe(1)
            // Stored as a calendar date, never a UTC instant: a season is
            // "1 June to 15 September" and converting it would move its edges.
            ->and($season->dateRanges()->first()?->starts_on->toDateString())->toBe('2026-06-01');
    });
})->group('fast');

it('refuses two overlapping ranges within one season, naming both', function (): void {
    // Always a mistake — usually a repeater row duplicated and half-edited —
    // and leaving it in makes the narrowest-range tie-break answer with a width
    // the operator never intended.
    seasonTenant(function (): void {
        try {
            saveSeason([], [
                ['starts_on' => '2026-06-01', 'ends_on' => '2026-07-31'],
                ['starts_on' => '2026-07-15', 'ends_on' => '2026-09-15'],
            ]);

            expect(false)->toBeTrue('the overlap was not refused');
        } catch (ValidationException $e) {
            $message = implode(' ', $e->validator->errors()->all());

            expect($message)->toContain('2026-06-01')->toContain('2026-07-15');
        }
    });
})->group('fast');

it('accepts two ranges in one season that touch without overlapping', function (): void {
    // The passing counterpart: a season that runs in spring and again in
    // autumn is ordinary, and consecutive days must not read as an overlap.
    seasonTenant(function (): void {
        $season = saveSeason([], [
            ['starts_on' => '2026-06-01', 'ends_on' => '2026-06-30'],
            ['starts_on' => '2026-07-01', 'ends_on' => '2026-07-31'],
        ]);

        expect($season->dateRanges()->count())->toBe(2);
    });
})->group('fast');

it('refuses a second season that ties on priority over the same dates', function (): void {
    // PRC-4, the rule this issue exists for.
    seasonTenant(function (): void {
        saveSeason(['code' => 'A'], [['starts_on' => '2026-06-01', 'ends_on' => '2026-09-15']]);

        expect(fn () => saveSeason(['code' => 'B', 'name' => ['el' => 'Δεύτερη', 'en' => 'Second']], [
            ['starts_on' => '2026-08-01', 'ends_on' => '2026-08-31'],
        ]))->toThrow(ValidationException::class);
    });
})->group('fast');

it('allows overlapping seasons at different priorities', function (): void {
    // The whole point of `priority`. "August" inside "Summer" is the commonest
    // pricing shape there is, and refusing it would make seasons useless.
    seasonTenant(function (): void {
        saveSeason(['code' => 'SUMMER', 'priority' => 10], [
            ['starts_on' => '2026-06-01', 'ends_on' => '2026-09-15'],
        ]);

        $august = saveSeason(['code' => 'AUG', 'priority' => 20, 'name' => ['el' => 'Αύγουστος', 'en' => 'August']], [
            ['starts_on' => '2026-08-01', 'ends_on' => '2026-08-31'],
        ]);

        expect($august->exists)->toBeTrue()
            ->and(SeasonCandidateResolver::resolve(Carbon::parse('2026-08-15'))?->getKey())
            ->toBe($august->getKey());
    });
})->group('fast');

it('allows a same-priority season whose dates do not overlap', function (): void {
    // A tie is only a tie on a shared date. Spring and autumn at the same
    // priority is a perfectly ordinary calendar.
    seasonTenant(function (): void {
        saveSeason(['code' => 'SPRING'], [['starts_on' => '2026-04-01', 'ends_on' => '2026-05-31']]);

        $autumn = saveSeason(['code' => 'AUTUMN', 'name' => ['el' => 'Φθινόπωρο', 'en' => 'Autumn']], [
            ['starts_on' => '2026-10-01', 'ends_on' => '2026-11-30'],
        ]);

        expect($autumn->exists)->toBeTrue();
    });
})->group('fast');

it('catches a tie created by editing an existing season', function (): void {
    // The check runs against the state the save would produce, not the state
    // before it — otherwise widening a range into a neighbour would slip past.
    seasonTenant(function (): void {
        saveSeason(['code' => 'A'], [['starts_on' => '2026-06-01', 'ends_on' => '2026-06-30']]);
        $b = saveSeason(['code' => 'B', 'name' => ['el' => 'Β', 'en' => 'B']], [
            ['starts_on' => '2026-08-01', 'ends_on' => '2026-08-31'],
        ]);

        expect(fn () => app(SaveSeason::class)($b, [], [
            ['starts_on' => '2026-06-15', 'ends_on' => '2026-08-31'],
        ]))->toThrow(ValidationException::class);
    });
})->group('fast');

it('resolves the highest-priority season containing a date', function (): void {
    seasonTenant(function (): void {
        saveSeason(['code' => 'SUMMER', 'priority' => 10], [['starts_on' => '2026-06-01', 'ends_on' => '2026-09-15']]);
        $august = saveSeason(['code' => 'AUG', 'priority' => 20, 'name' => ['el' => 'Αύγουστος', 'en' => 'August']], [
            ['starts_on' => '2026-08-01', 'ends_on' => '2026-08-31'],
        ]);

        expect(SeasonCandidateResolver::resolve(Carbon::parse('2026-08-15'))?->getKey())->toBe($august->getKey())
            // …and outside August, the wider season still applies.
            ->and(SeasonCandidateResolver::resolve(Carbon::parse('2026-07-15'))?->code)->toBe('SUMMER');
    });
})->group('fast');

it('returns null for a date in no season', function (): void {
    // A real answer: pricing then falls back to the product's default rate
    // plan, which is what `rate_plans.season_id` being nullable means.
    seasonTenant(function (): void {
        saveSeason([], [['starts_on' => '2026-06-01', 'ends_on' => '2026-09-15']]);

        expect(SeasonCandidateResolver::resolve(Carbon::parse('2026-01-15')))->toBeNull();
    });
})->group('fast');

it('ignores an inactive season', function (): void {
    seasonTenant(function (): void {
        saveSeason(['is_active' => false], [['starts_on' => '2026-06-01', 'ends_on' => '2026-09-15']]);

        expect(SeasonCandidateResolver::resolve(Carbon::parse('2026-07-15')))->toBeNull();
    });
})->group('fast');

it('never resolves another operator season', function (): void {
    // The resolver runs inside the tenant scope like everything else. A season
    // leaking across operators would price one fleet by another's calendar.
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    Tenancy::forTenant($b, function (): void {
        saveSeason(['code' => 'B'], [['starts_on' => '2026-06-01', 'ends_on' => '2026-09-15']]);
    });

    Tenancy::forTenant($a, function (): void {
        expect(SeasonCandidateResolver::resolve(Carbon::parse('2026-07-15')))->toBeNull();
    });
})->group('fast', 'tenancy');

it('resolves in a bounded number of queries however many seasons exist', function (): void {
    // NFR-6. A resolver that asked per season would make price quoting scale
    // with the size of an operator's calendar, and quoting happens on every
    // availability request.
    seasonTenant(function (): void {
        for ($i = 0; $i < 12; $i++) {
            $month = str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);

            saveSeason(
                ['code' => "M{$month}", 'priority' => $i + 1, 'name' => ['el' => "Μ{$month}", 'en' => "M{$month}"]],
                [['starts_on' => "2026-{$month}-01", 'ends_on' => "2026-{$month}-28"]],
            );
        }

        DB::enableQueryLog();
        SeasonCandidateResolver::resolve(Carbon::parse('2026-07-15'));
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // One for the seasons, one for the eager-loaded ranges.
        expect($queries)->toBeLessThanOrEqual(3);
    });
})->group('fast');

it('refuses two seasons with the same code, and allows two with none', function (): void {
    // `seasons_tenant_code_uq` is nullable-unique: NULL is distinct from NULL
    // on both engines, which is the one place that behaviour is what we want.
    seasonTenant(function (): void {
        saveSeason(['code' => null], [['starts_on' => '2026-04-01', 'ends_on' => '2026-04-30']]);
        saveSeason(['code' => null, 'priority' => 11], [['starts_on' => '2026-05-01', 'ends_on' => '2026-05-31']]);

        expect(Season::query()->count())->toBe(2);
    });
})->group('fast');

it('enforces the code uniqueness at the database', function (): void {
    seasonTenant(function (): void {
        Season::factory()->create(['code' => 'HIGH26']);
        Season::factory()->create(['code' => 'HIGH26']);
    });
})->throws(QueryException::class)->group('fast');

it('lets an owner and a manager reach the seasons page', function (Role $role): void {
    // `ManagePricing`: a season decides which prices apply, so it is money
    // rather than catalogue.
    //
    // One role per case, matching the rest of the suite. Chaining several
    // `actingAs` calls in one test signs the previous user out mid-test and the
    // second request redirects to login — a 302 that looks like a policy
    // failure and is not.
    actingAs(OperatorUser::withRole($role))->get('/app/seasons')->assertSuccessful();
})->with([[Role::Owner], [Role::Manager]])->group('fast');

it('refuses crew the seasons page', function (): void {
    actingAs(OperatorUser::withRole(Role::Crew))->get('/app/seasons')->assertForbidden();
})->group('fast');

it('renders the season labels in Greek and in English', function (): void {
    $user = OperatorUser::withRole(Role::Owner);

    // A row first: Filament renders the empty state instead of the column
    // headers when a table has no rows.
    Tenancy::forTenant(Tenant::query()->findOrFail($user->tenant_id), function (): void {
        Season::factory()->create();
    });

    actingAs($user)->get('/app/seasons?lang=el')
        ->assertSuccessful()
        ->assertSee(__('pricing.season.table.priority', locale: 'el'))
        ->assertDontSee('pricing.season.table.priority');

    actingAs($user)->get('/app/seasons?lang=en')
        ->assertSuccessful()
        ->assertSee(__('pricing.season.table.priority', locale: 'en'));
})->group('fast', 'i18n');
