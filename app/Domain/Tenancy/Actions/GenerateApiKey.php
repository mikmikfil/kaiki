<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Data\GeneratedApiKeyData;
use App\Enums\ApiKeyEnvironment;
use App\Enums\ApiKeyType;
use App\Enums\ApiScope;
use App\Models\ApiKey;
use App\Models\User;
use App\Support\Tenancy;
use DateTimeInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Mints a key, stores only its hash, and returns the plaintext once.
 *
 * Key shape: `{pk|sk}_{live|test}_{32 random base62 chars}`. The type and
 * environment are in the string itself, so anyone holding a key — an operator
 * pasting it into WordPress, a developer reading a bug report — knows what it
 * is without looking anything up. That readability is also what makes SEC-9's
 * CI grep for a leaked `sk_` possible.
 */
final class GenerateApiKey
{
    /**
     * @param  list<ApiScope>  $scopes
     * @param  list<string>  $allowedOrigins
     */
    public function __invoke(
        string $name,
        ApiKeyType $type,
        array $scopes,
        ApiKeyEnvironment $environment = ApiKeyEnvironment::Live,
        array $allowedOrigins = [],
        ?User $createdBy = null,
        ?DateTimeInterface $expiresAt = null,
    ): GeneratedApiKeyData {
        $this->guardScopes($type, $scopes);

        $secretLength = (int) config('kaiki.api_keys.secret_length');
        $prefixRandom = (int) config('kaiki.api_keys.prefix_random_length');
        $attempts = (int) config('kaiki.api_keys.generation_attempts');

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $secret = Str::random($secretLength);
            $rawKey = "{$type->keyPrefix()}_{$environment->value}_{$secret}";
            $prefix = "{$type->keyPrefix()}_{$environment->value}_" . substr($secret, 0, $prefixRandom);

            // `prefix` is globally unique, so the collision check must see every
            // tenant's keys, not just the current one.
            $taken = Tenancy::withoutTenancy(
                static fn (): bool => ApiKey::query()->where('prefix', $prefix)->exists(),
            );

            if ($taken) {
                continue;
            }

            $apiKey = ApiKey::query()->create([
                'name' => $name,
                'type' => $type,
                'environment' => $environment,
                'prefix' => $prefix,
                'secret_hash' => hash('sha256', $rawKey),
                'last_four' => substr($rawKey, -4),
                'scopes' => array_map(static fn (ApiScope $scope): string => $scope->value, $scopes),
                'allowed_origins' => array_values($allowedOrigins),
                'expires_at' => $expiresAt,
                'created_by_user_id' => $createdBy?->getKey(),
            ]);

            return new GeneratedApiKeyData($apiKey, $rawKey);
        }

        // Reaching here means the random source is broken, not that we were
        // unlucky: the odds of this many collisions on a 6-character prefix are
        // not worth expressing.
        throw new RuntimeException(
            "Could not generate a unique API key prefix in {$attempts} attempts. Check the random source.",
        );
    }

    /**
     * Type is the ceiling; scopes only narrow it (SEC-5).
     *
     * @param  list<ApiScope>  $scopes
     */
    private function guardScopes(ApiKeyType $type, array $scopes): void
    {
        foreach ($scopes as $scope) {
            if (! $type->permits($scope)) {
                throw new InvalidArgumentException(
                    "A {$type->value} key may not hold the scope [{$scope->value}]. "
                    . 'The key type is a ceiling that scopes can narrow but never widen (spec SEC-5).',
                );
            }
        }
    }
}
