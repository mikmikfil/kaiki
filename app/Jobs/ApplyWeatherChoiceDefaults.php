<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Booking\Actions\ApplyGuestChoice;
use App\Domain\Booking\Actions\CancelDeparture;
use App\Enums\CancelReason;
use App\Events\WeatherChoiceReminderDue;
use App\Models\Booking;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The two clocks CXL-7 runs on a guest who has not answered.
 *
 * ## Why the requirement exists at all
 *
 * CXL-7 is marked RESOLVED with its own reason: *"the brief leaves the
 * no-response case undefined and it must not strand money indefinitely."* A
 * guest who never opens the email otherwise leaves the operator holding money
 * that is not theirs, on a booking nobody will ever close, forever. Seventy-two
 * hours gets a reminder; fourteen days gets an answer.
 *
 * ## Cross-tenant, like the hold sweeper and for the same reason
 *
 * This is a platform job. It runs `withoutTenancy` to *find* the bookings and
 * then enters each one's tenant to act — which is the shape
 * {@see ExpireAbandonedCheckouts} settled and the reason
 * `bookings_weather_choice_idx` deliberately does not lead with `tenant_id`.
 *
 * ## The default is the operator's, not the platform's
 *
 * `tenants.weather_choice_default`, falling back to `refund` — the only one of
 * the three that cannot leave a guest holding credit they never asked for.
 * {@see CancelDeparture::defaultChoiceFor()} owns that resolution so the page
 * that *displays* the default and the job that *applies* it cannot disagree,
 * which would mean telling a guest one thing and doing another.
 *
 * ## Failures are per booking
 *
 * One guest's gateway refusing a refund must not stop the other forty on the
 * same cancelled sailing from being settled. Each is its own try/catch, and the
 * log carries ids rather than the row, because a booking is three pieces of
 * personal data and this runs every hour.
 */
class ApplyWeatherChoiceDefaults implements ShouldQueue
{
    use Queueable;

    /** @return array{0: int, 1: int} reminders sent, defaults applied */
    public function handle(ApplyGuestChoice $applyChoice): array
    {
        return [$this->remind(), $this->applyDefaults($applyChoice)];
    }

    /**
     * CXL-7's 72-hour reminder, once per booking.
     *
     * The once is `weather_choice_reminded_at`, written by the same conditional
     * update that selects the row — `notification_logs` (§6 item 40) is #87's
     * and does not exist yet, and a reminder whose idempotency waits on a table
     * nobody has built goes out every hour.
     */
    private function remind(): int
    {
        $cutoff = now()->subHours(self::reminderHours());

        $due = Tenancy::withoutTenancy(static fn () => Booking::query()
            ->where('cancel_reason', CancelReason::Weather->value)
            ->whereNull('weather_choice')
            ->whereNull('weather_choice_reminded_at')
            ->whereNotNull('weather_choice_due_at')
            ->where('cancelled_at', '<=', $cutoff)
            ->get());

        $sent = 0;

        foreach ($due as $booking) {
            // Conditional, so two overlapping sweeps send one email. Same shape
            // as the choice itself.
            $claimed = DB::table('bookings')
                ->where('id', $booking->getKey())
                ->whereNull('weather_choice_reminded_at')
                ->update(['weather_choice_reminded_at' => now(), 'updated_at' => now()]);

            if ($claimed < 1) {
                continue;
            }

            WeatherChoiceReminderDue::dispatch(
                $booking->getKey(),
                $booking->tenant_id,
                $booking->weather_choice_due_at,
            );

            $sent++;
        }

        return $sent;
    }

    /** CXL-7's fourteen days: the operator's default, applied and notified. */
    private function applyDefaults(ApplyGuestChoice $applyChoice): int
    {
        $overdue = Tenancy::withoutTenancy(static fn () => Booking::query()
            ->where('cancel_reason', CancelReason::Weather->value)
            ->whereNull('weather_choice')
            ->whereNotNull('weather_choice_due_at')
            ->where('weather_choice_due_at', '<=', now())
            ->get());

        $applied = 0;

        foreach ($overdue as $booking) {
            if ($this->applyDefaultTo($booking, $applyChoice)) {
                $applied++;
            }
        }

        return $applied;
    }

    private function applyDefaultTo(Booking $booking, ApplyGuestChoice $applyChoice): bool
    {
        $tenant = Tenancy::withoutTenancy(
            static fn (): ?Tenant => Tenant::query()->find($booking->tenant_id),
        );

        if ($tenant === null) {
            return false;
        }

        try {
            return Tenancy::forTenant($tenant, function () use ($booking, $tenant, $applyChoice): bool {
                $applyChoice(
                    booking: $booking,
                    choice: CancelDeparture::defaultChoiceFor($tenant),
                    ip: null,
                    // What makes the email say "we have refunded you" rather
                    // than "as you asked". CXL-7 requires the guest be notified,
                    // and a guest who chose nothing is being told, not confirmed.
                    automatic: true,
                );

                return true;
            });
        } catch (Throwable $exception) {
            Log::warning('booking.weather_choice_default_failed', [
                'booking_id' => $booking->getKey(),
                'tenant_id' => $booking->tenant_id,
                'exception' => $exception::class,
            ]);

            return false;
        }
    }

    /** CXL-7's seventy-two hours, from config. */
    public static function reminderHours(): int
    {
        return (int) config('kaiki.booking.weather_choice_reminder_hours', 72);
    }
}
