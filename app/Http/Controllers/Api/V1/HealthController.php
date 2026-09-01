<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/health` — the versioning smoke endpoint.
 *
 * Deliberately **authenticated**. An unauthenticated health route would be the
 * only endpoint in the API with no tenant, and the one route in the file that
 * a future change could quietly hang something else off. It also makes the
 * endpoint answer a more useful question than "is the server up": *is this key
 * accepted, and which API version is answering it* — which is what an
 * integrator debugging a 401 actually needs.
 *
 * Liveness for the load balancer is `/up` (Laravel's health route, configured
 * in `bootstrap/app.php`), which is unauthenticated and outside `/api/v1`.
 *
 * Returns no tenant identifier. A key already implies its tenant, and echoing
 * a slug or uuid here would make this the cheapest tenant-enumeration oracle in
 * the product.
 */
final class HealthController
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'status' => 'ok',
                'version' => 'v1',
            ],
        ]);
    }
}
