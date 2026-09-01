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
            // No preference, which is what a real new user has. A factory
            // that pins a locale makes steps 4 and 5 of the I18N-5 chain
            // untestable for an authenticated user.
            'locale' => null,
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
