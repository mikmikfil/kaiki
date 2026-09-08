<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DeliveryStatus;
use App\Enums\WebhookEvent;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<WebhookDelivery> */
class WebhookDeliveryFactory extends Factory
{
    protected $model = WebhookDelivery::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $eventId = (string) Str::uuid();

        return [
            'webhook_endpoint_id' => WebhookEndpoint::factory(),
            'event' => WebhookEvent::BookingConfirmed,
            'event_id' => $eventId,
            // A minimal but *shaped* envelope: a factory whose payload is
            // `[]` would let a signing or serialisation bug pass every test
            // that used it.
            'payload' => [
                'id' => $eventId,
                'event' => WebhookEvent::BookingConfirmed->value,
                'api_version' => '1',
                'is_test' => false,
                'data' => [],
            ],
            'status' => DeliveryStatus::Pending,
            'attempts' => 0,
            'next_attempt_at' => now(),
        ];
    }

    public function delivered(): self
    {
        return $this->state(fn (): array => [
            'status' => DeliveryStatus::Delivered,
            'attempts' => 1,
            'next_attempt_at' => null,
            'response_status' => 200,
            'delivered_at' => now(),
            'duration_ms' => 120,
        ]);
    }

    /** Out of attempts — the state OPS-21's feed shows with a retry button. */
    public function failed(): self
    {
        return $this->state(fn (): array => [
            'status' => DeliveryStatus::Failed,
            'attempts' => WebhookDelivery::maxAttempts(),
            'next_attempt_at' => null,
            'response_status' => 500,
            'response_body' => 'Internal Server Error',
        ]);
    }
}
