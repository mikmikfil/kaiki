<?php

declare(strict_types=1);

use App\Console\Commands\SchemaSnapshotCommand;
use Tests\Support\WorkflowFile;

/*
|--------------------------------------------------------------------------
| CI gate guards
|--------------------------------------------------------------------------
|
| ENV-23 lists the checks that must be required on `main`. That list lives in
| three places that can drift apart: the jobs defined in ci.yml, the `needs:`
| of the single aggregating check, and the documentation someone reads while
| configuring branch protection. These tests pin all three together.
|
| A gate that stops running reports green, which is worse than no gate (ENV-11,
| TST-8) - so "somebody added a job and forgot to make it required" fails here
| rather than months later in a post-mortem.
|
*/

function ciWorkflow(): string
{
    return base_path('.github/workflows/ci.yml');
}

it('runs every ENV-23 check somewhere in the CI workflow', function (): void {
    // **Commands, not job names**, since #90 merged sixteen jobs into nine.
    //
    // A list of job ids asserted that the *shape* of the pipeline had not
    // changed, which is not what ENV-23 asks for — it names twelve checks and
    // says they run, not that each gets its own runner. Worse, a job id is
    // exactly the thing a refactor renames, so the old test failed on a
    // rearrangement that dropped nothing and would have passed a rename that
    // dropped everything.
    //
    // The command is the check. If `composer i18n:check` disappears from this
    // file, the EL/EN gate is gone whatever the jobs are called.
    $workflow = (string) file_get_contents(ciWorkflow());

    $required = [
        'composer lint:test' => 'Pint',
        'composer stan' => 'PHPStan level 6 (TST-10)',
        'composer i18n:check' => 'EL/EN parity and the hardcoded-string lint (I18N-2, I18N-3)',
        'composer test:api-docs' => 'the OpenAPI contract drift gate (ENV-28)',
        'composer test:coverage' => 'the app/Domain coverage gate (TST-1, ENV-22)',
        'composer test:mysql' => 'the mysql group, run for real (ENV-11, TST-8)',
        'composer test:tenancy' => 'the cross-tenant isolation gate (ADR-0001)',
        '--group=chromium' => 'the PDF rendering group (ENV-20)',
        "--filter='Availability'" => 'the third-timezone availability run (ENV-14)',
        'npm run widget:build' => 'the widget build (WGT-2)',
        'npm run widget:size' => 'the 80 KB budget (NFR-3)',
        'npm run e2e' => 'the Playwright smoke (TST-3)',
        'npm run plugin:lint' => 'the WordPress plugin standard (WPP-11)',
        './.github/workflows/dependency-audit.yml' => 'the dependency audit (SEC-12)',
        './.github/workflows/schema-drift.yml' => 'the schema drift gate (ENV-10)',
    ];

    $absent = [];

    foreach ($required as $command => $what) {
        if (! str_contains($workflow, $command)) {
            $absent[] = sprintf('%s — %s', $command, $what);
        }
    }

    expect($absent)->toBe([], implode("\n", [
        'These ENV-23 checks are no longer run by .github/workflows/ci.yml:',
        ...$absent,
    ]));
})->group('fast');

it('requires every CI job through the single aggregating check', function (): void {
    // Branch protection makes one check required: `ci-passed`. A job missing
    // from its `needs:` runs, goes red, and merges anyway.
    $jobs = array_values(array_diff(WorkflowFile::jobIds(ciWorkflow()), ['ci-passed']));
    $needed = WorkflowFile::aggregatedJobIds(ciWorkflow());

    sort($jobs);
    sort($needed);

    expect($needed)->toBe($jobs);
})->group('fast');

it('documents exactly the required status checks that CI enforces', function (): void {
    // ENV-23: the list someone types into branch protection has to be the list
    // the workflow actually produces, in both directions.
    $documented = WorkflowFile::documentedRequiredChecks(base_path('docs/ci.md'));
    $needed = WorkflowFile::aggregatedJobIds(ciWorkflow());

    sort($documented);
    sort($needed);

    expect($documented)->toBe($needed);
})->group('fast');

it('schedules the nightly workflow and names its deferred placeholders', function (): void {
    $path = base_path('.github/workflows/nightly.yml');

    expect(is_file($path))->toBeTrue();

    $nightly = (string) file_get_contents($path);

    // ENV-25 lists four things. Two of them do not exist yet; the workflow has
    // to say which, and when they arrive, rather than quietly omitting them.
    expect($nightly)->toContain('schedule:')
        ->and($nightly)->toContain('cron:')
        ->and($nightly)->toContain('TST-3')
        ->and($nightly)->toContain('NFR-1');
})->group('fast');

it('keeps the coverage threshold in exactly one place', function (): void {
    $composer = WorkflowFile::composerManifest(base_path('composer.json'));

    expect($composer['scripts'])->toHaveKey('test:coverage')
        ->and($composer['scripts-descriptions'])->toHaveKey('test:coverage');

    $script = implode(' ', (array) $composer['scripts']['test:coverage']);

    // Deliberately not asserting the number itself. Pinning it here would make
    // this test the second place the threshold lives, which is the exact thing
    // the test exists to prevent.
    expect($script)->toMatch('/--min=\d+/')
        ->and($script)->toContain('phpunit.coverage.xml');

    expect((string) file_get_contents(ciWorkflow()))->not->toContain('--min=');
})->group('fast');

it('scopes the coverage source to app/Domain without drifting from phpunit.xml', function (): void {
    // TST-1 is explicit that the gate covers app/Domain and not the whole
    // application - a global gate rewards testing Filament resources instead
    // of the engine.
    $coverage = (string) file_get_contents(base_path('phpunit.coverage.xml'));

    expect($coverage)->toContain('<directory>app/Domain</directory>')
        ->and($coverage)->not->toContain('<directory>app</directory>');

    // Compared whole, modulo the source filter and comments. Restricting the
    // comparison to the <php> block would let the test suites, the bootstrap
    // or the root attributes drift apart unnoticed, and a coverage run under a
    // different configuration measures something other than what CI runs.
    $skeleton = static function (string $path): string {
        $xml = (string) file_get_contents($path);
        $xml = (string) preg_replace('/<!--.*?-->/s', '', $xml);
        $xml = (string) preg_replace('/<source>.*?<\/source>/s', '', $xml);

        return trim((string) preg_replace('/\s+/', ' ', $xml));
    };

    expect($skeleton(base_path('phpunit.coverage.xml')))
        ->toBe($skeleton(base_path('phpunit.xml')));
})->group('fast');

it('exposes the schema snapshot refresh as one composer command', function (): void {
    $composer = WorkflowFile::composerManifest(base_path('composer.json'));

    expect($composer['scripts'])->toHaveKey('schema:snapshot')
        ->and($composer['scripts-descriptions'])->toHaveKey('schema:snapshot');
})->group('fast');

it('runs every workflow on the one PHP version composer.json requires', function (): void {
    // ADR-0014, Option B: 8.4 everywhere. Extracting the audit and schema jobs
    // into reusable workflows gave each of them its own input default, because
    // GitHub does not allow the `env` context in a job's `with:` - so the
    // version genuinely lives in more than one file and this is what keeps
    // those files honest.
    $declared = [];

    foreach (glob(base_path('.github/workflows/*.yml')) ?: [] as $workflow) {
        $declared = [...$declared, ...WorkflowFile::phpVersions($workflow)];
    }

    $composer = WorkflowFile::composerManifest(base_path('composer.json'));

    expect($declared)->not->toBeEmpty()
        ->and(array_values(array_unique($declared)))
        ->toBe([WorkflowFile::constraintToMajorMinor($composer['require']['php'])]);
})->group('fast');

it('keeps the scripts the CI jobs call, stub or not', function (): void {
    // The names are the contract between the workflow and the repository. Four
    // of these were stubs when the jobs were written so that M3 and M4 could
    // fill a pipeline in rather than invent one; **`widget:build` and
    // `widget:size` stopped being stubs in #106** and the assertion did not have
    // to change, which is the whole point of having made it about the names.
    //
    // `e2e` and `plugin:lint` are still stubs, and leave the same way.
    /** @var array{scripts: array<string, string>} $package */
    $package = json_decode((string) file_get_contents(base_path('package.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($package['scripts'])->toHaveKeys([
        'widget:build', 'widget:size', 'widget:guards', 'widget:test', 'e2e', 'plugin:lint',
    ]);
})->group('fast');

it('builds the widget from a real package rather than an echo', function (): void {
    // The negative half of the assertion above, and the one that would have
    // caught #106 shipping a workflow that ran a stub: a job named "Widget
    // build" whose script prints a sentence is a green square that means
    // nothing.
    /** @var array{scripts: array<string, string>, workspaces?: list<string>} $package */
    $package = json_decode((string) file_get_contents(base_path('package.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($package['scripts']['widget:build'])->not->toContain('console.log')
        ->and($package['scripts']['widget:size'])->not->toContain('console.log')
        ->and($package['workspaces'] ?? [])->toContain('packages/*')
        ->and(is_file(base_path('packages/widget/package.json')))->toBeTrue()
        ->and(is_file(base_path('packages/widget/src/index.tsx')))->toBeTrue();
})->group('fast');

it('never commits a schema dump at any path Laravel auto-loads', function (): void {
    // MigrateCommand::prepareDatabase() runs a dump found at
    // database/schema/{connection}-schema.dump - checked FIRST - or
    // {connection}-schema.sql, instead of running the migrations, whenever no
    // migration has run yet. A file at either would turn every from-scratch
    // MySQL migration into a replay of the dump and the guarantee would vanish
    // without a single red build.
    //
    // Globbed rather than named: the connection is part of the filename, so a
    // local `schema:dump` on the SQLite stack writes sqlite-schema.sql and
    // neuters that developer's own migrate with nothing to warn them.
    $loadable = [
        ...glob(base_path('database/schema/*-schema.sql')) ?: [],
        ...glob(base_path('database/schema/*-schema.dump')) ?: [],
    ];

    expect($loadable)->toBe([]);

    $gitignore = (string) file_get_contents(base_path('.gitignore'));

    expect($gitignore)->toContain('/database/schema/*-schema.sql')
        ->and($gitignore)->toContain('/database/schema/*-schema.dump');
})->group('fast');

it('keeps the committed schema snapshot in step with the migrations', function (): void {
    // ENV-10 is verified against MySQL 8 in CI. This is the cheap half of it:
    // when the migrations change and nobody refreshes the snapshot, say so in
    // seconds on SQLite rather than after a MySQL job round-trip.
    $snapshot = base_path('database/schema/mysql-schema.snapshot.sql');

    expect(is_file($snapshot))->toBeTrue();

    $header = (string) file_get_contents($snapshot);

    expect($header)->toMatch('/-- migrations-fingerprint: sha256:[0-9a-f]{64}/');

    preg_match('/-- migrations-fingerprint: sha256:(?P<hash>[0-9a-f]{64})/', $header, $matches);

    expect($matches['hash'])->toBe(SchemaSnapshotCommand::migrationsFingerprint());
})->group('fast');
