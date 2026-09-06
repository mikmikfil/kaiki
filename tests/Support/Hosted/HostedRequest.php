<?php

declare(strict_types=1);

namespace Tests\Support\Hosted;

use function Pest\Laravel\get;

/**
 * A request to the hosted-page host.
 *
 * HOS-1 puts these pages on `book.{platform-domain}`, and TEN-4's third
 * strategy reads the first path segment as an operator slug **only there**. A
 * test that asked for `/{slug}` on the default host would be asking for a route
 * that does not exist, and would pass or fail for a reason unrelated to what it
 * is testing.
 *
 * A class rather than a Pest helper, for the reason the seven scenario classes
 * give: three test files need it and a `function` in a Pest file is scoped to
 * that file — two of them declaring it is a fatal redeclaration, which is
 * exactly what happened.
 */
final class HostedRequest
{
    /**
     * An **absolute** URL on the hosted host.
     *
     * A URL rather than a wrapper around the request, for two reasons. Laravel's
     * route-domain matching reads the host from the URI and not from a `Host`
     * header, so the absolute form is the one that works — which is how #7's own
     * tenant tests already do it. And a helper that took the test case would
     * have to be handed `$this`, which inside a Pest closure is a `TestCall` at
     * analysis time rather than a `TestCase` — the same thing that bit
     * `$this->fail()` in #89, and twenty-nine PHPStan errors here.
     */
    public static function url(string $path): string
    {
        return 'http://' . self::host() . $path;
    }

    public static function host(): string
    {
        return (string) config('kaiki.tenancy.hosted_host');
    }

    /**
     * The response headers of a hosted page, plus its body.
     *
     * Here rather than beside the tests that read it for the same reason
     * {@see self::url()} is: the version that took the test case had to be
     * handed `$this`, and inside a Pest closure that is a `TestCall`. Nothing in
     * here needs the test case at all — `Pest\Laravel\get()` resolves the one
     * that is running — so the parameter was only ever there to look like a
     * helper method.
     *
     * @return array<string, string>
     */
    public static function headers(string $slug): array
    {
        $response = get(self::url('/' . $slug));

        $response->assertOk();

        return [
            'csp' => (string) $response->headers->get('Content-Security-Policy'),
            'nosniff' => (string) $response->headers->get('X-Content-Type-Options'),
            'referrer' => (string) $response->headers->get('Referrer-Policy'),
            'robots' => (string) $response->headers->get('X-Robots-Tag'),
            'body' => (string) $response->getContent(),
        ];
    }
}
