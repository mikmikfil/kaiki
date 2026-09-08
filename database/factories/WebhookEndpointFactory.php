<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WebhookEvent;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WebhookEndpoint> */
class WebhookEndpointFactory extends Factory
{
    protected $model = WebhookEndpoint::class;

    /**
     * `signing_secret` is deliberately absent: the model mints one on create,
     * and a factory that set it by hand would let a real writer forget to.
     *
     * The URL is `https://` and a public hostname, because `SafeUrl` refuses
     * anything else and a factory producing rows the product would reject is a
     * factory that tests the wrong thing.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Λογιστήριο',
            'url' => 'https://example.com/kaiki-webhook/' . $this->faker->unique()->numberBetween(1, 99999),
            'events' => WebhookEvent::names(),
            'is_active' => true,
            'consecutive_failures' => 0,
        ];
    }

    /** Subscribed to some of the four rather than all of them. */
    public function subscribedTo(WebhookEvent ...$events): self
    {
        return $this->state(fn (): array => [
            'events' => array_map(static fn (WebhookEvent $e): string => $e->value, $events),
        ]);
    }

    /** Switched off by the twenty-failure rule. */
    public function disabled(): self
    {
        return $this->state(fn (): array => [
            'is_active' => false,
            'disabled_at' => now(),
            'consecutive_failures' => WebhookEndpoint::FAILURE_LIMIT,
        ]);
    }
}
