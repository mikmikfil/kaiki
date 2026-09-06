<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NotificationChannel;
use App\Enums\NotificationProvider;
use App\Enums\NotificationStatus;
use App\Enums\NotificationTemplate;
use App\Models\Booking;
use App\Models\NotificationLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationLog>
 *
 * A sent confirmation email by default: the ordinary row, so a test about
 * failures has to arrange one and cannot get it by accident.
 */
class NotificationLogFactory extends Factory
{
    protected $model = NotificationLog::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'channel' => NotificationChannel::Mail,
            'template' => NotificationTemplate::BookingConfirmed,
            'locale' => 'el',
            'to' => 'maria@example.gr',
            'status' => NotificationStatus::Sent,
            'provider' => NotificationProvider::Postmark,
            'provider_ref' => 'pm_' . fake()->uuid(),
            'sent_at' => now(),
        ];
    }

    public function failed(string $error = 'postmark_unreachable'): self
    {
        return $this->state(fn (): array => [
            'status' => NotificationStatus::Failed,
            'error_message' => $error,
            'sent_at' => null,
        ]);
    }

    /** NTF-8: the provider accepted it and the mailbox rejected it. */
    public function bounced(): self
    {
        return $this->state(fn (): array => ['status' => NotificationStatus::Bounced]);
    }

    public function sms(): self
    {
        return $this->state(fn (): array => [
            'channel' => NotificationChannel::Sms,
            'to' => '+306912345678',
            'provider' => NotificationProvider::NullGateway,
        ]);
    }

    public function template(NotificationTemplate $template): self
    {
        return $this->state(fn (): array => ['template' => $template]);
    }
}
