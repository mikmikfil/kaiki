<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DomainStatus;
use App\Models\TenantDomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TenantDomain> */
class TenantDomainFactory extends Factory
{
    protected $model = TenantDomain::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'hostname' => $this->faker->unique()->domainName(),
            'status' => DomainStatus::Verified,
            'verified_at' => now(),
        ];
    }

    public function pending(): self
    {
        return $this->state(fn (): array => [
            'status' => DomainStatus::Pending,
            'verified_at' => null,
            'verification_token' => bin2hex(random_bytes(16)),
        ]);
    }

    public function disabled(): self
    {
        return $this->state(fn (): array => ['status' => DomainStatus::Disabled]);
    }
}
