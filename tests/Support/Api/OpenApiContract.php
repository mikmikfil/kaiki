<?php

declare(strict_types=1);

namespace Tests\Support\Api;

use App\Enums\ApiKeyType;
use App\Enums\ApiScope;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * Reads the shapes out of `docs/api.md` §5 that the ENV-28 drift gate compares
 * against the routes the application actually exposes.
 *
 * **Deliberately a line scanner, not a YAML parser.** There is no YAML parser
 * available: `symfony/yaml` is not installed, PHP's `yaml` extension is not
 * enabled, and Node ships none — and every candidate is a package outside
 * ARC-20 and ADR-0019, which CLAUDE.md makes a hard stop rather than a
 * judgement call. `Tests\Support\WorkflowFile` made exactly this call in #4 for
 * exactly this reason.
 *
 * That constraint is what bounds this class. It reads the four things §10.4
 * needs that are legible without a parser — the set of paths, the methods under
 * each, `operationId`, and the `security` scheme names — and deliberately does
 * **not** attempt required request-body fields or `$ref` targets, which need
 * real YAML semantics. Those are proposed in ADR-0026; wiring them here with
 * regex would be a gate that looks stronger than it is, which is worse than one
 * whose limits are written down.
 *
 * The document is 4,100 lines written by hand in one house style, and §10.1
 * pins the single-fenced-block property this depends on. Both facts are
 * asserted rather than assumed.
 */
final class OpenApiContract
{
    /** The methods an OpenAPI path item may carry. */
    private const METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    public static function path(): string
    {
        return base_path('docs/api.md');
    }

    /**
     * How many fenced ```yaml blocks the contract holds.
     *
     * §10.1: "There is exactly one fenced `yaml` block in this document, by
     * design — a second one would make the extraction ambiguous, so do not add
     * another." Everything below depends on that, so it is checked.
     */
    public static function fencedYamlBlockCount(): int
    {
        $count = 0;

        foreach (self::lines(self::path()) as $line) {
            if (rtrim($line) === '```yaml') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * The contract document itself, without its fence.
     *
     * @return list<string>
     */
    public static function contractLines(): array
    {
        $inside = false;
        $yaml = [];

        foreach (self::lines(self::path()) as $line) {
            if (! $inside && rtrim($line) === '```yaml') {
                $inside = true;

                continue;
            }

            if ($inside && rtrim($line) === '```') {
                break;
            }

            if ($inside) {
                $yaml[] = $line;
            }
        }

        return $yaml;
    }

    /**
     * Every operation the contract documents.
     *
     * @return list<array{path: string, method: string, operationId: string, security: list<string>}>
     */
    public static function documentedOperations(): array
    {
        $lines = self::contractLines();
        $global = self::globalSecurity($lines);

        $operations = [];

        foreach (self::operationBlocks($lines) as $block) {
            $operations[] = [
                'path' => $block['path'],
                'method' => $block['method'],
                'operationId' => self::scalar($block['lines'], 'operationId'),
                'security' => self::operationSecurity($block['lines'], $global),
            ];
        }

        return $operations;
    }

    /**
     * Split the `paths:` block into one entry per operation, keeping the raw
     * lines of each.
     *
     * Two passes rather than one, because a single pass has to carry "am I
     * inside a security list" across loop iterations, and state that spans
     * iterations is where a scanner like this goes wrong — as well as being
     * the kind of thing static analysis is right to distrust.
     *
     * @param  list<string>  $lines
     * @return list<array{path: string, method: string, lines: list<string>}>
     */
    private static function operationBlocks(array $lines): array
    {
        $methods = implode('|', self::METHODS);

        $blocks = [];
        $inPaths = false;
        $path = null;
        $index = null;

        foreach ($lines as $line) {
            if (preg_match('/^paths:\s*$/', $line) === 1) {
                $inPaths = true;

                continue;
            }

            if (! $inPaths) {
                continue;
            }

            // A non-indented, non-blank line ends the paths block.
            if (preg_match('/^\S/', $line) === 1) {
                break;
            }

            // `  /api/v1/branding:`
            if (preg_match('/^ {2}(\/\S*?):\s*$/', $line, $m) === 1) {
                $path = $m[1];
                $index = null;

                continue;
            }

            // `    get:`
            if ($path !== null && preg_match("/^ {4}({$methods}):\s*$/", $line, $m) === 1) {
                $blocks[] = ['path' => $path, 'method' => $m[1], 'lines' => []];
                $index = count($blocks) - 1;

                continue;
            }

            if ($index !== null) {
                $blocks[$index]['lines'][] = $line;
            }
        }

        return $blocks;
    }

    /**
     * The schemes an operation accepts.
     *
     * An operation-level `security` replaces the document-level one outright,
     * per OpenAPI — starting from an empty list rather than from the global
     * default is what makes an `sk_`-only endpoint readable as `sk_`-only.
     *
     * @param  list<string>  $lines
     * @param  list<string>  $global
     * @return list<string>
     */
    private static function operationSecurity(array $lines, array $global): array
    {
        $schemes = null;

        foreach ($lines as $offset => $line) {
            if (preg_match('/^ {6}security:\s*$/', $line) !== 1) {
                continue;
            }

            $schemes = [];

            foreach (array_slice($lines, $offset + 1) as $item) {
                if (preg_match('/^ {8}- (\w+):/', $item, $m) !== 1) {
                    break;
                }

                $schemes[] = $m[1];
            }

            break;
        }

        $schemes ??= $global;
        sort($schemes);

        return $schemes;
    }

    /**
     * A six-space scalar key inside an operation, or an empty string.
     *
     * @param  list<string>  $lines
     */
    private static function scalar(array $lines, string $key): string
    {
        foreach ($lines as $line) {
            if (preg_match("/^ {6}{$key}:\s*(\S+)\s*$/", $line, $m) === 1) {
                return $m[1];
            }
        }

        return '';
    }

    /**
     * Every route the application exposes under `/api/v1`, with the key types
     * its middleware actually accepts.
     *
     * @return list<array{path: string, method: string, security: list<string>}>
     */
    public static function implementedRoutes(): array
    {
        $routes = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                // Laravel registers HEAD alongside every GET; the contract
                // documents GET only, and a HEAD reported as undocumented would
                // be noise on every single endpoint forever.
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $routes[] = [
                    'path' => '/' . $route->uri(),
                    'method' => strtolower($method),
                    'security' => self::securityFor($route),
                ];
            }
        }

        return $routes;
    }

    /**
     * Routes that exist but are absent from the contract.
     *
     * @return list<array{path: string, method: string}>
     */
    public static function undocumentedRoutes(): array
    {
        $documented = [];

        foreach (self::documentedOperations() as $operation) {
            $documented[] = "{$operation['method']} {$operation['path']}";
        }

        $missing = [];

        foreach (self::implementedRoutes() as $route) {
            if (! in_array("{$route['method']} {$route['path']}", $documented, true)) {
                $missing[] = ['path' => $route['path'], 'method' => $route['method']];
            }
        }

        return $missing;
    }

    /**
     * Endpoints whose enforced key types disagree with the contract.
     *
     * The half of drift a route list cannot show: an endpoint documented as
     * `sk_`-only that quietly accepts a publishable key is SEC-5 turning into a
     * leak rather than a documentation error.
     *
     * @return list<array{path: string, method: string, documented: list<string>, actual: list<string>}>
     */
    public static function securityMismatches(): array
    {
        $documented = [];

        foreach (self::documentedOperations() as $operation) {
            $documented["{$operation['method']} {$operation['path']}"] = $operation['security'];
        }

        $mismatches = [];

        foreach (self::implementedRoutes() as $route) {
            $key = "{$route['method']} {$route['path']}";

            if (! array_key_exists($key, $documented)) {
                // Reported by undocumentedRoutes(); not counted twice.
                continue;
            }

            $expected = $documented[$key];
            $actual = $route['security'];

            sort($expected);
            sort($actual);

            if ($expected !== $actual) {
                $mismatches[] = [
                    'path' => $route['path'],
                    'method' => $route['method'],
                    'documented' => $expected,
                    'actual' => $actual,
                ];
            }
        }

        return $mismatches;
    }

    /**
     * Documented operations with no route yet — the contract surface still to
     * build, derived rather than maintained by hand.
     *
     * @return list<string>
     */
    public static function unbuiltPaths(): array
    {
        $implemented = [];

        foreach (self::implementedRoutes() as $route) {
            $implemented[] = "{$route['method']} {$route['path']}";
        }

        $unbuilt = [];

        foreach (self::documentedOperations() as $operation) {
            $key = "{$operation['method']} {$operation['path']}";

            if (! in_array($key, $implemented, true)) {
                $unbuilt[] = strtoupper($operation['method']) . ' ' . $operation['path'];
            }
        }

        sort($unbuilt);

        return $unbuilt;
    }

    /**
     * The key types a route's middleware actually accepts.
     *
     * `api.key` alone accepts either type. An `api.scope:` requirement narrows
     * it whenever a publishable key is not permitted to hold that scope — which
     * is the type ceiling from SEC-5 and ADR-0013, read from the same enum the
     * middleware enforces with rather than from a second list here.
     *
     * @return list<string>
     */
    private static function securityFor(RoutingRoute $route): array
    {
        $middleware = $route->gatherMiddleware();

        $accepts = [];

        foreach ($middleware as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            if ($entry === 'api.key' || str_starts_with($entry, 'api.key:')) {
                $accepts = ['PublishableKey', 'SecretKey'];
            }
        }

        foreach ($middleware as $entry) {
            if (! is_string($entry) || ! str_starts_with($entry, 'api.scope:')) {
                continue;
            }

            foreach (explode(',', substr($entry, strlen('api.scope:'))) as $name) {
                $scope = ApiScope::tryFrom(trim($name));

                if ($scope !== null && ! ApiKeyType::Publishable->permits($scope)) {
                    $accepts = ['SecretKey'];
                }
            }
        }

        sort($accepts);

        return $accepts;
    }

    /**
     * The document-level `security:` an operation inherits when it declares none.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    private static function globalSecurity(array $lines): array
    {
        $schemes = [];
        $reading = false;

        foreach ($lines as $line) {
            if (preg_match('/^security:\s*$/', $line) === 1) {
                $reading = true;

                continue;
            }

            if (! $reading) {
                continue;
            }

            if (preg_match('/^ {2}- (\w+):/', $line, $m) === 1) {
                $schemes[] = $m[1];

                continue;
            }

            if (trim($line) !== '') {
                break;
            }
        }

        sort($schemes);

        return $schemes;
    }

    /** @return list<string> */
    private static function lines(string $path): array
    {
        return file($path, FILE_IGNORE_NEW_LINES) ?: [];
    }
}
