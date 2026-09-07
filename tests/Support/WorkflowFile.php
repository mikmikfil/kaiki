<?php

declare(strict_types=1);

namespace Tests\Support;

use JsonException;

/**
 * Reads the shapes out of the CI workflow files that CiGatesTest asserts on.
 *
 * Deliberately a line scanner rather than a YAML parser: symfony/yaml is not an
 * approved dependency and ARC-19 makes adding one a hard stop, while the shapes
 * being read are four fixed files written in this repository.
 */
final class WorkflowFile
{
    /**
     * The job identifiers defined under `jobs:` in a workflow file.
     *
     * @return list<string>
     */
    public static function jobIds(string $path): array
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
     * The job identifiers listed in the `needs:` of one named job.
     *
     * Anchored to that job rather than taking the first `needs:` in the file:
     * an unanchored match would silently start reading some other job's list
     * the day a second job gains one, and pass while a gate went unaggregated.
     *
     * @return list<string>
     */
    public static function aggregatedJobIds(string $path, string $job = 'ci-passed'): array
    {
        $yaml = (string) file_get_contents($path);

        $block = '/^ {2}' . preg_quote($job, '/') . ':\s*$(?P<block>.*?)(?=^ {2}\S|\z)/ms';

        if (preg_match($block, $yaml, $matches) !== 1) {
            return [];
        }

        if (preg_match('/^\s*needs:\s*\[(?P<list>[^\]]*)\]/m', $matches['block'], $needs) !== 1) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $id): string => trim($id),
            explode(',', $needs['list']),
        )));
    }

    /**
     * The job identifiers documented as required status checks in docs/ci.md.
     *
     * The first column of the table only. Prose in the second column mentions
     * plenty of other backticked names, and none of them is a status check.
     *
     * @return list<string>
     */
    public static function documentedRequiredChecks(string $path): array
    {
        $docs = (string) file_get_contents($path);

        if (preg_match('/<!-- required-checks:start -->(?P<block>.*?)<!-- required-checks:end -->/s', $docs, $matches) !== 1) {
            return [];
        }

        preg_match_all('/^\|\s*`([a-z0-9][a-z0-9_-]*)`\s*\|/m', $matches['block'], $found);

        return $found[1];
    }

    /**
     * Every PHP version a workflow pins, from `PHP_VERSION:` and from the
     * `default:` of a `php-version:` input.
     *
     * Scoped to those two shapes on purpose. A bare `default:` match would also
     * catch, say, a `mysql-version: '8.0'` input the day one is added.
     *
     * @return list<string>
     */
    public static function phpVersions(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
        $found = [];

        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*PHP_VERSION:\s*\'(?P<version>\d+\.\d+)\'/', $line, $matches) === 1) {
                $found[] = $matches['version'];

                continue;
            }

            // A version written straight into a step's `with:`, which is how the
            // WordPress plugin's job pins 8.1. Read as well as the two shapes
            // around it, because a job that quietly chose its own version would
            // otherwise be invisible to the test that exists to catch exactly
            // that.
            if (preg_match('/^\s*php-version:\s*\'(?P<version>\d+\.\d+)\'/', $line, $matches) === 1) {
                $found[] = $matches['version'];

                continue;
            }

            if (preg_match('/^(?P<indent>\s*)php-version:\s*$/', $line, $matches) !== 1) {
                continue;
            }

            // Walk the input's own block, stopping at the next key at the same
            // indentation or shallower.
            $indent = strlen($matches['indent']);

            for ($i = $index + 1; $i < count($lines); $i++) {
                $next = $lines[$i];

                if (trim($next) === '') {
                    continue;
                }

                if (strlen($next) - strlen(ltrim($next)) <= $indent) {
                    break;
                }

                if (preg_match('/^\s*default:\s*\'(?P<version>\d+\.\d+)\'/', $next, $default) === 1) {
                    $found[] = $default['version'];
                }
            }
        }

        return $found;
    }

    /**
     * composer.json, decoded.
     *
     * @return array{require: array<string, string>, scripts: array<string, mixed>, scripts-descriptions: array<string, string>}
     *
     * @throws JsonException
     */
    public static function composerManifest(string $path): array
    {
        /** @var array{require: array<string, string>, scripts: array<string, mixed>, scripts-descriptions: array<string, string>} $manifest */
        $manifest = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return $manifest;
    }

    /**
     * The major.minor of a Composer version constraint: `^8.4` becomes `8.4`.
     *
     * Tolerates `>=`, `~` and a patch component, none of which `ltrim()` on a
     * fixed character list survives.
     */
    public static function constraintToMajorMinor(string $constraint): string
    {
        preg_match('/(?P<version>\d+\.\d+)/', $constraint, $matches);

        return $matches['version'] ?? $constraint;
    }
}
