<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ApiKeyEnvironment;
use App\Enums\ApiKeyType;
use App\Enums\ApiScope;
use App\Models\ApiKey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ApiKey>
 *
 * Produces a row that looks like a real key without knowing the plaintext.
 * Tests that need to authenticate should use the `GenerateApiKey` action, which
 * is the only path that returns a usable key — building one here with an
 * invented hash would test a code path that does not exist in production.
 */
class ApiKeyFactory extends Factory
{
    protected $model = ApiKey::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $secret = Str::random(32);

        return [
            'name' => $this->faker->words(2, true),
            'type' => ApiKeyType::Publishable,
            'environment' => ApiKeyEnvironment::Live,
            'prefix' => 'pk_live_' . substr($secret, 0, 6),
            'secret_hash' => hash('sha256', 'pk_live_' . $secret),
            'last_four' => substr($secret, -4),
            'scopes' => array_map(static fn (ApiScope $s): string => $s->value, ApiScope::readScopes()),
            'allowed_origins' => [],
        ];
    }

    public function secret(): self
    {
        return $this->state(function (): array {
            $secret = Str::random(32);

            return [
                'type' => ApiKeyType::Secret,
                'prefix' => 'sk_live_' . substr($secret, 0, 6),
                'secret_hash' => hash('sha256', 'sk_live_' . $secret),
                'scopes' => ApiScope::values(),
            ];
        });
    }

    public function revoked(): self
    {
        return $this->state(fn (): array => ['revoked_at' => now()->subDay()]);
    }

    public function expired(): self
    {
        return $this->state(fn (): array => ['expires_at' => now()->subHour()]);
    }
}
