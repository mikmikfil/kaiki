<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Role;
use App\Models\RoleAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RoleAssignment> */
class RoleAssignmentFactory extends Factory
{
    protected $model = RoleAssignment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'role' => Role::Manager,
        ];
    }

    public function owner(): self
    {
        return $this->state(fn (): array => ['role' => Role::Owner]);
    }

    public function crew(): self
    {
        return $this->state(fn (): array => ['role' => Role::Crew]);
    }
}
