<?php

declare(strict_types=1);

namespace Tests\Support\Api;

use App\Domain\Tenancy\Actions\GenerateApiKey;
use App\Enums\ApiKeyEnvironment;
use App\Enums\ApiKeyType;
use App\Enums\ApiScope;
use App\Models\Tenant;
use App\Support\Tenancy;

/**
 * A tenant and a key that may read its catalogue.
 *
 * Shared by the four `GET /products` test files rather than redeclared in each,
 * because Pest's file-level helper functions live in one global namespace and a
 * second `catalogKey()` in a second file is a fatal redeclare — a failure that
 * reads as "the whole suite is broken" rather than "two files named a function
 * the same".
 */
final class CatalogRequest
{
    /**
     * @param  list<ApiScope>  $scopes
     * @param  list<string>  $allowedOrigins  empty means any, as it does for CORS
     * @return array{0: Tenant, 1: string} the tenant and a usable plaintext key
     */
    public static function key(
        ?Tenant $tenant = null,
        ApiKeyType $type = ApiKeyType::Publishable,
        array $scopes = [ApiScope::ProductsRead],
        ApiKeyEnvironment $environment = ApiKeyEnvironment::Live,
        array $allowedOrigins = [],
    ): array {
        $tenant ??= Tenant::factory()->create();

        $plain = Tenancy::forTenant($tenant, fn (): string => (new GenerateApiKey)(
            name: 'Widget',
            type: $type,
            scopes: $scopes,
            environment: $environment,
            allowedOrigins: $allowedOrigins,
        )->plainTextKey);

        return [$tenant, $plain];
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public static function url(string $path, array $query = []): string
    {
        return $query === []
            ? "/api/v1{$path}"
            : "/api/v1{$path}?" . http_build_query($query);
    }
}
