<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
            'locale' => 'el',
            'is_super_admin' => false,
            'remember_token' => Str::random(10),
        ];
    }

    /** A platform super-admin: no tenant, `/admin` panel only. */
    public function superAdmin(): self
    {
        return $this->state(fn (): array => [
            'tenant_id' => null,
            'is_super_admin' => true,
            'locale' => 'en',
        ]);
    }

    public function forTenant(Tenant $tenant): self
    {
        return $this->state(fn (): array => ['tenant_id' => $tenant->getKey()]);
    }

    public function unverified(): self
    {
        return $this->state(fn (): array => ['email_verified_at' => null]);
    }
}
