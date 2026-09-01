<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\Support\Api\OpenApiContract;

/*
 * Spec ENV-28: `docs/api.md` is the contract, Scramble generates the
 * implementation's view of it, and CI reconciles the two so the contract can
 * never drift silently.
 *
 * ---
 *
 * **The comparison is directional: implemented ⊆ documented.**
 *
 * `docs/api.md` §10.4 says the diff compares "the set of paths and methods".
 * Taken literally that is red from today until M3, because the contract
 * describes sixteen endpoints and this milestone builds one — and a gate that
 * is red for months is a gate somebody switches off. So:
 *
 *   - every route the application exposes MUST appear in the contract, with a
 *     matching method and matching security schemes. That is drift, and it
 *     fails.
 *   - a documented path with no route yet is *not built*, not drift. The list
 *     is derived, never maintained by hand, and printed on every run so the gap
 *     stays visible and shrinks on its own.
 *
 * When the last endpoint lands the unbuilt list is empty and this becomes the
 * full equality §10.4 asks for, with no code change. The §10.4 wording is
 * proposed to the architect in the pull request rather than edited here — the
 * same route #4 and #12 took.
 */

it('finds exactly one fenced YAML block in the contract', function (): void {
    // §10.1 states there is exactly one, by design, "a second one would make the
    // extraction ambiguous". Everything below depends on that being true, so it
    // is asserted rather than trusted.
    expect(OpenApiContract::fencedYamlBlockCount())->toBe(1);
})->group('fast', 'api-docs');

it('documents every route the application actually exposes', function (): void {
    $undocumented = OpenApiContract::undocumentedRoutes();

    $report = array_map(
        static fn (array $r): string => "{$r['method']} {$r['path']}",
        $undocumented,
    );

    expect($report)->toBe([], implode("\n", [
        'These routes exist but are not in docs/api.md §5:',
        ...$report,
        '',
        'Add them to the contract (and say why in CHANGELOG.md, per §10.5),',
        'or remove the route. Never regenerate docs/api.md from the code —',
        'the direction of authority runs the other way.',
    ]));
})->group('fast', 'api-docs');

it('matches the security schemes the contract declares for each route', function (): void {
    // The half of drift that is invisible from a route list: an endpoint that
    // exists, is documented, and quietly accepts a publishable key where the
    // contract says `sk_` only. That is SEC-5 turning into a leak rather than a
    // documentation error.
    $mismatches = OpenApiContract::securityMismatches();

    expect($mismatches)->toBe([], implode("\n", array_map(
        static fn (array $m): string => "{$m['method']} {$m['path']}: contract says ["
            . implode(', ', $m['documented']) . '], route enforces ['
            . implode(', ', $m['actual']) . ']',
        $mismatches,
    )));
})->group('fast', 'api-docs');

it('reports the contract surface that is not built yet', function (): void {
    // Not an assertion about the number — that would need editing every time an
    // endpoint lands, which is how a number becomes a lie. It asserts only that
    // the gap is knowable and shrinking towards zero, and prints it so a human
    // reading the job output can see how much of the contract is real.
    $unbuilt = OpenApiContract::unbuiltPaths();
    $documented = OpenApiContract::documentedOperations();
    $built = count($documented) - count($unbuilt);

    // Printed, not just returned. A gap nobody sees is a gap nobody closes, and
    // the whole reason the diff is directional rather than strict is that the
    // unbuilt list is expected to shrink to nothing by M3. Three lines in the
    // job output is what makes that progress legible.
    fwrite(STDERR, sprintf(
        "\n  contract surface: %d of %d operations built%s\n",
        $built,
        count($documented),
        $unbuilt === [] ? '' : ' — not yet: ' . implode(', ', array_slice($unbuilt, 0, 5))
            . (count($unbuilt) > 5 ? sprintf(' … and %d more', count($unbuilt) - 5) : ''),
    ));

    expect($documented)->not->toBeEmpty('the contract scanner found no operations at all')
        ->and(count($unbuilt))->toBeLessThanOrEqual(count($documented));
})->group('fast', 'api-docs');

it('has no route under /api/v1 that resolves a resource by database id', function (): void {
    // CNV-8 and SEC-2. Cheap now; it is the test that stops M1 exposing
    // `/api/v1/bookings/{id}` and leaking how many bookings the platform holds.
    $offenders = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1')) {
            continue;
        }

        foreach ($route->parameterNames() as $parameter) {
            if ($parameter === 'id' || str_ends_with($parameter, '_id')) {
                $offenders[] = "{$route->uri()} takes {{$parameter}}";
            }
        }
    }

    expect($offenders)->toBe([], implode('; ', $offenders));
})->group('fast', 'api-docs');

it('generates a document whose every path is in the contract', function (): void {
    // ENV-28 and §10.3–4 speak about *the generated document*, not about the
    // route table. Comparing routes alone would pass a world where Scramble
    // cannot see a route at all — which is a real failure mode, because it
    // infers from code and gives up quietly on shapes it does not understand.
    //
    // Generated here rather than read from disk so the assertion cannot pass
    // against a stale artefact somebody exported last week.
    //
    // The directory is ensured rather than assumed. `build/` is committed with
    // its own self-ignoring `.gitignore` so `composer api:docs` works on a
    // clean checkout — it did not on the first push, because the directory
    // existed only on the machine where it had been created by hand, and every
    // Pest job in CI failed on `file_put_contents(): No such file`.
    File::ensureDirectoryExists(base_path('build'));

    Artisan::call('scramble:export');

    $generated = json_decode((string) file_get_contents(base_path('build/openapi.generated.json')), true);

    expect($generated)->toBeArray()
        ->and($generated['openapi'] ?? null)->toStartWith('3.1');

    $documented = array_map(
        static fn (array $o): string => "{$o['method']} {$o['path']}",
        OpenApiContract::documentedOperations(),
    );

    $missing = [];

    // Scramble folds the `/api/v1` prefix into `servers`, so its path keys are
    // relative; the contract writes them absolute. Rejoin before comparing.
    foreach ((array) ($generated['paths'] ?? []) as $path => $operations) {
        foreach (array_keys((array) $operations) as $method) {
            $absolute = strtolower((string) $method) . ' /api/v1' . $path;

            if (! in_array($absolute, $documented, true)) {
                $missing[] = $absolute;
            }
        }
    }

    expect($missing)->toBe([], 'generated but not in docs/api.md §5: ' . implode(', ', $missing));
})->group('fast', 'api-docs');

it('never commits the generated document', function (): void {
    // `docs/api.md` is the contract; §10.5 says it is never regenerated from
    // the code. A generated OpenAPI file tracked in the tree beside it is how
    // someone eventually edits the wrong one — the same reasoning that keeps
    // the schema snapshot out of the path Laravel auto-loads (#4).
    $tracked = trim((string) shell_exec('git ls-files build/ 2>&1'));

    $files = $tracked === '' ? [] : explode('
', str_replace('', '', $tracked));

    expect($files)->toBe(
        ['build/.gitignore'],
        'build/ should track only its .gitignore, found: ' . implode(', ', $files),
    );
})->group('fast', 'api-docs');
