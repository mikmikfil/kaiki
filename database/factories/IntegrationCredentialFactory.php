<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CredentialEnvironment;
use App\Enums\IntegrationProvider;
use App\Models\IntegrationCredential;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IntegrationCredential>
 *
 * Every state fills the fields its provider actually requires, so a row built
 * here answers `isComplete()` truthfully. A factory that produced a half-filled
 * credential set would make `isUsable()` false everywhere and quietly turn every
 * downstream test into a test of the incomplete path.
 *
 * The values are obvious nonsense on purpose — `fake()->password()` would
 * produce something that reads like a real secret in a failure diff.
 */
class IntegrationCredentialFactory extends Factory
{
    protected $model = IntegrationCredential::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'provider' => IntegrationProvider::Viva,
            'environment' => CredentialEnvironment::Test,
            'credentials' => [
                'client_id' => 'test-client-' . Str::random(8),
                'client_secret' => 'test-secret-' . Str::random(16),
            ],
            'public_config' => ['source_code' => (string) $this->faker->numberBetween(1000, 9999)],
            'external_account_id' => null,
            'is_default' => false,
            'is_active' => true,
            'verified_at' => null,
            'last_error' => null,
            'webhook_secret' => null,
        ];
    }

    public function forProvider(IntegrationProvider $provider): self
    {
        return $this->state(fn (): array => [
            'provider' => $provider,
            'credentials' => $this->credentialsFor($provider),
            'public_config' => $this->publicConfigFor($provider),
            'webhook_secret' => $provider->issuesWebhookSecret() ? 'test-signing-' . Str::random(16) : null,
        ]);
    }

    public function stripe(): self
    {
        return $this->forProvider(IntegrationProvider::Stripe);
    }

    public function live(): self
    {
        return $this->state(fn (): array => ['environment' => CredentialEnvironment::Live]);
    }

    /** Verified, active and complete — the state a working integration is in. */
    public function verified(): self
    {
        return $this->state(fn (): array => [
            'verified_at' => now(),
            'last_error' => null,
            'is_active' => true,
        ]);
    }

    public function default(): self
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false, 'is_default' => false]);
    }

    /** @return array<string, string> */
    private function credentialsFor(IntegrationProvider $provider): array
    {
        $credentials = [];

        foreach ($provider->credentialFields() as $field) {
            $credentials[$field] = 'test-' . str_replace('_', '-', $field) . '-' . Str::random(12);
        }

        return $credentials;
    }

    /** @return array<string, string> */
    private function publicConfigFor(IntegrationProvider $provider): array
    {
        $config = [];

        foreach ($provider->publicFields() as $field) {
            $config[$field] = 'test-' . str_replace('_', '-', $field);
        }

        return $config;
    }
}
