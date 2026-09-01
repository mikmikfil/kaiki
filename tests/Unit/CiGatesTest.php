<?php

declare(strict_types=1);

use App\Console\Commands\SchemaSnapshotCommand;

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

/**
 * The job identifiers defined under `jobs:` in a workflow file.
 *
 * Deliberately a line scanner rather than a YAML parser: symfony/yaml is not an
 * approved dependency (ARC-19), and the shape being read is fixed - two-space
 * job keys directly under `jobs:`.
 *
 * @return list<string>
 */
function workflowJobIds(string $path): array
{
    $inJobs = false;
    $ids = [];

    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^jobs:\s*$/', $line) === 1) {
            $inJobs = true;

            continue;
        }

        // A non-indented, non-comment line ends the jobs block.
        if ($inJobs && preg_match('/^[^\s#]/', $line) === 1) {
            break;
        }

        if ($inJobs && preg_match('/^ {2}([a-z0-9][a-z0-9_-]*):\s*$/', $line, $matches) === 1) {
            $ids[] = $matches[1];
        }
    }

    return $ids;
}

/**
 * The job identifiers listed in the `needs:` of the aggregating check.
 *
 * @return list<string>
 */
function aggregatedJobIds(string $path): array
{
    $yaml = (string) file_get_contents($path);

    if (preg_match('/^\s*needs:\s*\[(?P<list>[^\]]*)\]/m', $yaml, $matches) !== 1) {
        return [];
    }

    return array_values(array_filter(array_map(
        static fn (string $id): string => trim($id),
        explode(',', $matches['list']),
    )));
}

/**
 * The job identifiers documented as required status checks.
 *
 * @return list<string>
 */
function documentedRequiredChecks(): array
{
    $docs = (string) file_get_contents(base_path('docs/ci.md'));

    if (preg_match('/<!-- required-checks:start -->(?P<block>.*?)<!-- required-checks:end -->/s', $docs, $matches) !== 1) {
        return [];
    }

    // The first column of the table only. Prose in the second column mentions
    // plenty of other backticked names, and none of them is a status check.
    preg_match_all('/^\|\s*`([a-z0-9][a-z0-9_-]*)`\s*\|/m', $matches['block'], $found);

    return $found[1];
}

it('defines every ENV-23 job in the CI workflow', function (): void {
    $jobs = workflowJobIds(base_path('.github/workflows/ci.yml'));

    expect($jobs)->toContain(
        'lint',
        'static-analysis',
        'test-sqlite',
        'test-mysql',
        'tenancy-isolation',
        'coverage',
        'security-audit',
        'migrate-from-zero',
        'widget-build',
        'plugin-lint',
    );
})->group('fast');

it('requires every CI job through the single aggregating check', function (): void {
    // Branch protection makes one check required: `ci-passed`. A job missing
    // from its `needs:` runs, goes red, and merges anyway.
    $path = base_path('.github/workflows/ci.yml');

    $jobs = array_values(array_diff(workflowJobIds($path), ['ci-passed']));
    $needed = aggregatedJobIds($path);

    sort($jobs);
    sort($needed);

    expect($needed)->toBe($jobs);
})->group('fast');

it('documents exactly the required status checks that CI enforces', function (): void {
    // ENV-23: the list someone types into branch protection has to be the list
    // the workflow actually produces, in both directions.
    $documented = documentedRequiredChecks();
    $needed = aggregatedJobIds(base_path('.github/workflows/ci.yml'));

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
    $composer = composerManifest();

    expect($composer['scripts'])->toHaveKey('test:coverage')
        ->and($composer['scripts-descriptions'])->toHaveKey('test:coverage');

    $script = implode(' ', (array) $composer['scripts']['test:coverage']);

    // Deliberately not asserting the number itself. Pinning it here would make
    // this test the second place the threshold lives, which is the exact thing
    // the test exists to prevent.
    expect($script)->toMatch('/--min=\d+/')
        ->and($script)->toContain('phpunit.coverage.xml');

    // TST-1 raises this number later. Raising it must be a one-line change, so
    // the workflow calls the composer script and never repeats the figure.
    expect((string) file_get_contents(base_path('.github/workflows/ci.yml')))
        ->not->toContain('--min=');
})->group('fast');

it('scopes the coverage source to app/Domain without drifting from phpunit.xml', function (): void {
    // TST-1 is explicit that the gate covers app/Domain and not the whole
    // application - a global gate rewards testing Filament resources instead
    // of the engine.
    $coverage = (string) file_get_contents(base_path('phpunit.coverage.xml'));

    expect($coverage)->toContain('<directory>app/Domain</directory>')
        ->and($coverage)->not->toContain('<directory>app</directory>');

    $phpBlock = static function (string $path): string {
        preg_match('/<php>(?P<block>.*?)<\/php>/s', (string) file_get_contents($path), $matches);

        return trim((string) preg_replace('/\s+/', ' ', $matches['block'] ?? ''));
    };

    // Two config files means two sets of test environment defaults, and a
    // coverage run against a different environment measures the wrong thing.
    expect($phpBlock(base_path('phpunit.coverage.xml')))
        ->toBe($phpBlock(base_path('phpunit.xml')));
})->group('fast');

it('exposes the schema snapshot refresh as one composer command', function (): void {
    $composer = composerManifest();

    expect($composer['scripts'])->toHaveKey('schema:snapshot')
        ->and($composer['scripts-descriptions'])->toHaveKey('schema:snapshot');
})->group('fast');

it('runs every workflow on the one PHP version composer.json requires', function (): void {
    // ADR-0014, Option B: 8.4 everywhere. Extracting the audit and schema jobs
    // into reusable workflows gave each of them its own default, so the version
    // now appears in more than one file - and a pipeline testing a PHP the
    // application does not require is a pipeline testing the wrong thing.
    $declared = [];

    foreach (glob(base_path('.github/workflows/*.yml')) ?: [] as $workflow) {
        preg_match_all(
            '/^\s*(?:PHP_VERSION|default):\s*\'(\d+\.\d+)\'/m',
            (string) file_get_contents($workflow),
            $found,
        );

        $declared = [...$declared, ...$found[1]];
    }

    $composer = composerManifest();

    expect($declared)->not->toBeEmpty()
        ->and(array_values(array_unique($declared)))
        ->toBe([ltrim((string) $composer['require']['php'], '^~')]);
})->group('fast');

it('keeps the widget and plugin stubs the CI jobs depend on', function (): void {
    // WGT-2 and WPP-11 land in M3 and M4. The jobs exist now so those
    // milestones fill a pipeline in rather than invent one, which only works
    // if the scripts they call keep their names.
    /** @var array{scripts: array<string, string>} $package */
    $package = json_decode((string) file_get_contents(base_path('package.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($package['scripts'])->toHaveKeys(['widget:build', 'widget:size', 'plugin:lint']);
})->group('fast');

it('never commits a schema dump at the path Laravel auto-loads', function (): void {
    // MigrateCommand::prepareDatabase() loads database/schema/{connection}-schema.sql
    // instead of running the migrations whenever no migration has run yet. A
    // file there would turn every from-scratch MySQL migration - the ENV-10 job
    // and test-mysql's migrate:fresh alike - into a replay of the dump, and the
    // guarantee would vanish without a single red build.
    expect(is_file(base_path('database/schema/mysql-schema.sql')))->toBeFalse()
        ->and((string) file_get_contents(base_path('.gitignore')))
        ->toContain('/database/schema/*-schema.sql');
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

/**
 * @return array{require: array<string, string>, scripts: array<string, mixed>, scripts-descriptions: array<string, string>}
 */
function composerManifest(): array
{
    /** @var array{require: array<string, string>, scripts: array<string, mixed>, scripts-descriptions: array<string, string>} $manifest */
    $manifest = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    return $manifest;
}
