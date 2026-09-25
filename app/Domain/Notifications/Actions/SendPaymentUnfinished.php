<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Actions;

use App\Domain\Booking\Actions\ResumeAbandonedBooking;
use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Enums\NotificationChannel;
use App\Enums\NotificationTemplate;
use App\Mail\GuestMail;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\NotificationLog;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * «Η κράτησή σας για … δεν ολοκληρώθηκε» — one email, never a second (24/9).
 *
 * The timeline Mike approved in the payments mockup: the guest goes to the
 * payment page and does not come back; at 60 minutes the checkout expires as
 * it always has and the seats go back on sale; the email goes **then**, and
 * its button makes a fresh booking that checks availability again
 * ({@see ResumeAbandonedBooking}).
 *
 * Not sent when:
 * - the guest has already booked the same sailing again,
 * - the boat has no room left for the party,
 * - it leaves in less than {@see self::MIN_HOURS_AHEAD} hours,
 * - the booking is a test one, or already had this email.
 *
 * Quiet hours are not consulted: the guest was on the page an hour ago, the
 * email is silent, and one deferred to the morning would be about a boat that
 * may have sailed. NTF-7's note is on {@see NotificationTemplate::PaymentUnfinished}.
 */
final class SendPaymentUnfinished
{
    public const MIN_HOURS_AHEAD = 3;

    /** How long after expiry it may still go, so a first run does not mail old ones. */
    public const GRACE_HOURS = 6;

    public function __construct(private readonly SendNotification $notifications) {}

    /** @return int how many went */
    public function __invoke(?Carbon $now = null): int
    {
        $now ??= now();

        $bookings = Tenancy::withoutTenancy(static fn () => Booking::query()
            ->where('status', BookingStatus::Expired->value)
            ->where('cancel_reason', CancelReason::PaymentFailed->value)
            ->where('is_test', false)
            ->whereNotNull('guest_email')
            ->where('updated_at', '>=', $now->copy()->subHours(self::GRACE_HOURS))
            ->where('starts_at_utc', '>', $now->copy()->addHours(self::MIN_HOURS_AHEAD))
            ->get());

        $sent = 0;

        foreach ($bookings as $booking) {
            $tenant = Tenancy::withoutTenancy(static fn (): ?Tenant => Tenant::query()->find($booking->tenant_id));

            if (! $tenant instanceof Tenant) {
                continue;
            }

            try {
                $sent += (int) Tenancy::forTenant($tenant, function () use ($booking): int {
                    $template = NotificationTemplate::PaymentUnfinished;

                    if (NotificationLog::alreadySent($booking->getKey(), $template, NotificationChannel::Mail)
                        || ResumeAbandonedBooking::successor($booking) instanceof Booking
                        || ! $this->stillFits($booking)) {
                        return 0;
                    }

                    $this->notifications->mail($booking, $template, new GuestMail($booking, $template));

                    return 1;
                });
            } catch (Throwable $exception) {
                Log::warning('notifications.payment_unfinished_failed', [
                    'booking_id' => $booking->getKey(),
                    'tenant_id' => $booking->tenant_id,
                    'exception' => $exception::class,
                ]);
            }
        }

        return $sent;
    }

    /** Is there still room for this party? A boat that filled gets no email. */
    private function stillFits(Booking $booking): bool
    {
        if ($booking->departure_id === null) {
            return true;
        }

        $departure = Departure::query()->find($booking->departure_id);

        return $departure instanceof Departure
            && $departure->status->isSellable()
            && $departure->seatsAvailable() >= $booking->pax_capacity_total;
    }
}
