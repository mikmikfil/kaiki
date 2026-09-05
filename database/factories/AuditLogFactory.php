<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AuditLog> */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /**
     * A revoked API key — the action that prompted ADR-0025 in the first place.
     *
     * `context` is deliberately a small machine-readable map with **no personal
     * data** (ADR-0025 §3). A factory that put a name in it would be the first
     * example anybody copied.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'action' => AuditAction::ApiKeyRevoked,
            'subject_type' => 'api_key',
            'subject_id' => $this->faker->numberBetween(1, 9999),
            'subject_label' => 'pk_live_' . $this->faker->lexify('????????'),
            'reason' => null,
            'context' => ['environment' => 'live'],
            'ip_address' => $this->faker->ipv4(),
        ];
    }

    /** No actor: a scheduled job, a webhook, or an erased user. */
    public function system(): self
    {
        return $this->state(fn (): array => ['user_id' => null, 'ip_address' => null]);
    }

    public function action(AuditAction $action): self
    {
        return $this->state(fn (): array => ['action' => $action]);
    }
}
