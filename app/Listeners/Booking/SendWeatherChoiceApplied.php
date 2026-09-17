<?php

declare(strict_types=1);

namespace App\Listeners\Booking;

use App\Domain\Notifications\Actions\SendNotification;
use App\Enums\NotificationTemplate;
use App\Events\WeatherChoiceApplied;
use App\Mail\GuestMail;
use App\Models\Booking;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * CXL-7's "applied and notified": the operator's default, chosen by the
 * deadline because the guest never answered (product owner, 2026-09-17).
 *
 * ## Only when the deadline chose
 *
 * `WeatherChoiceApplied` fires for both: the guest's own click on the booking
 * page and the sweep at the deadline. The template says «we did not hear back,
 * so we applied the usual choice», which is true only of the second. A guest
 * who has just picked a refund sees it confirmed on the page they picked it on,
 * and an email telling them nobody answered would be wrong.
 *
 * The facts are what was done and how much moved, from the event, and the
 * voucher code when the default issued one.
 */
class SendWeatherChoiceApplied implements ShouldQueue
{
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(private readonly SendNotification $notifications) {}

    public function handle(WeatherChoiceApplied $event): void
    {
        if (! $event->automatic) {
            return;
        }

        $tenant = Tenancy::withoutTenancy(
            static fn (): ?Tenant => Tenant::query()->find($event->tenantId),
        );

        if (! $tenant instanceof Tenant) {
            return;
        }

        Tenancy::forTenant($tenant, function () use ($event): void {
            $booking = Booking::query()->find($event->bookingId);

            if (! $booking instanceof Booking || trim((string) $booking->guest_email) === '') {
                return;
            }

            $this->notifications->mail(
                $booking,
                NotificationTemplate::WeatherChoiceApplied,
                new GuestMail($booking, NotificationTemplate::WeatherChoiceApplied, [
                    'choice' => $event->choice,
                    'amount_cents' => $event->amountCents,
                ]),
            );
        });
    }
}
