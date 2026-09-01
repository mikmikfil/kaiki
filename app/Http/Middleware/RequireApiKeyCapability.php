<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ApiScope;
use App\Http\Responses\ApiErrorResponse;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires a scope on the authenticated key, before any controller runs.
 *
 * Route usage: `->middleware('api.scope:bookings.write')`.
 *
 * The check sits in middleware rather than in a controller because SEC-5
 * requires a publishable key to be refused a privileged write *before*
 * application code executes — a controller that authorises after loading a
 * booking has already revealed whether that booking exists.
 */
final class RequireApiKeyCapability
{
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $apiKey = $request->attributes->get('api_key');

        if (! $apiKey instanceof ApiKey) {
            // The route is misconfigured: this must run after the
            // authentication middleware. A loud failure in development beats a
            // silently unauthenticated endpoint in production.
            throw new InvalidArgumentException(
                'RequireApiKeyCapability ran without an authenticated API key. '
                . 'Place it after the api-key authentication middleware on the route.',
            );
        }

        foreach ($scopes as $scope) {
            $required = ApiScope::tryFrom($scope);

            if ($required === null) {
                throw new InvalidArgumentException(
                    "Unknown API scope [{$scope}]. Scopes are dot form and must exist in App\Enums\ApiScope.",
                );
            }

            if (! $apiKey->can($required)) {
                return ApiErrorResponse::fromKey(
                    key: 'api.errors.insufficient_scope',
                    code: 'insufficient_scope',
                    status: 403,
                    replace: ['scope' => $scope],
                    details: ['required_scope' => $scope],
                );
            }
        }

        return $next($request);
    }
}
