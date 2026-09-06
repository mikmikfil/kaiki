<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Actions;

use App\Domain\Notifications\Support\QuietHours;
use App\Domain\Notifications\Support\SmsComposer;
use App\Enums\BookingStatus;
use App\Enums\GuestDetailsStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationTemplate;
use App\Mail\GuestMail;
use App\Models\Booking;
use App\Models\NotificationLog;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * BKG-16's five families, swept (spec BKG-15, BKG-16, BKG-17, BKG-18).
 *
 * ## A sweep rather than jobs scheduled at confirmation
 *
 * BKG-13.7 says *"schedule the reminder jobs"*, and scheduling a delayed job per
 * reminder per booking is the obvious reading. It is also the one that goes
 * wrong: a booking whose departure moves, whose balance is paid, whose guest
 * details are completed or which is cancelled leaves four delayed jobs on the
 * queue that nobody can find and that will fire anyway.
 *
 * So the schedule is **derived from the booking on every pass**, and every
 * family's suppression condition — BKG-16's own "suppressed when" column — is
 * evaluated at send time. A guest who finishes their passport details an hour
 * before the reminder simply does not get one.
 *
 * ## Idempotency is the log, not the queue
 *
 * *"Each idempotent per booking per reminder type."* The dedupe read is
 * {@see NotificationLog::alreadySent()}, on `notif_logs_tenant_tmpl_idx`, per
 * channel as well as per template. Running this twice in the same minute sends
 * once, and `ReminderIdempotencyTest` asserts exactly that.
 *
 * ## BKG-18 applies here, not inside the mailer
 *
 * The night-time rule is a question about **when this reminder was due**, and
 * only the caller knows what it warns about. `QuietHours::decide()` gets both,
 * and its third answer — drop, because 08:00 is after the departure — is
 * recorded as a log row rather than a silence.
 *
 * ## Cross-tenant, like every other platform sweep
 *
 * `withoutTenancy()` to find, then into each tenant to act. The same shape as
 * the hold sweeper, the abandoned-checkout sweeper and the weather-choice
 * sweeper, and for the same reason: this is a platform job, and the tenant
 * timezone is what BKG-18 measures its window in.
 */
final class SendDueReminders
{
    public function __construct(private readonly SendNotification $notifications) {}

    /** @return int how many messages were put in motion */
    public function __invoke(?Carbon $now = null): int
    {
        $now ??= now();

        $bookings = Tenancy::withoutTenancy(static fn () => Booking::query()
            ->whereIn('status', [
                BookingStatus::Confirmed->value,
                BookingStatus::CheckedIn->value,
            ])
            // Nothing to remind anybody about after the boat has gone. The one
            // reminder that outlives a departure is the voucher expiry, and a
            // voucher is not attached to a sailing.
            ->where('starts_at_utc', '>', $now)
            ->where('is_test', false)
            ->get());

        $sent = 0;

        foreach ($bookings as $booking) {
            $sent += $this->forBooking($booking, $now);
        }

        return $sent;
    }

    private function forBooking(Booking $booking, Carbon $now): int
    {
        $tenant = Tenancy::withoutTenancy(
            static fn (): ?Tenant => Tenant::query()->find($booking->tenant_id),
        );

        if ($tenant === null) {
            return 0;
        }

        try {
            return (int) Tenancy::forTenant($tenant, function () use ($booking, $tenant, $now): int {
                $sent = 0;

                foreach ($this->dueFor($booking, $now) as [$template, $dueAt]) {
                    $sent += $this->deliver($booking, $tenant, $template, $dueAt, $now);
                }

                return $sent;
            });
        } catch (Throwable $exception) {
            // Ids and the class. A booking carries a guest's name, email and
            // phone, and a sweep that logged the row would write all three
            // every quarter of an hour.
            Log::warning('notifications.reminder_sweep_failed', [
                'booking_id' => $booking->getKey(),
                'tenant_id' => $booking->tenant_id,
                'exception' => $exception::class,
            ]);

            return 0;
        }
    }

    /**
     * BKG-16's table, evaluated against this booking right now.
     *
     * Each entry carries **the instant it warns about** as well as its own due
     * time, because BKG-18's drop rule needs both: a reminder deferred to 08:00
     * is only worth sending if 08:00 is still before the thing it is about.
     *
     * @return list<array{0: NotificationTemplate, 1: Carbon}>
     */
    private function dueFor(Booking $booking, Carbon $now): array
    {
        $due = [];
        $departure = $booking->starts_at_utc;

        // 1. Guest details — deadline −48h and −24h, suppressed when complete
        //    or not required (BKG-15, BKG-16).
        if ($booking->guest_details_status === GuestDetailsStatus::Pending) {
            $deadline = $departure->copy()->subHours($this->guestDetailsDeadlineHours($booking));

            foreach ([48 => NotificationTemplate::GuestDetailsReminder48h, 24 => NotificationTemplate::GuestDetailsReminder24h] as $hours => $template) {
                $at = $deadline->copy()->subHours($hours);

                if ($at->lessThanOrEqualTo($now)) {
                    $due[] = [$template, $at];
                }
            }
        }

        // 2. Balance due — per ADR-0018, suppressed when there is nothing to pay.
        if ($booking->balance_cents > 0 && $booking->balance_due_at !== null) {
            foreach ([7, 1] as $days) {
                $at = $booking->balance_due_at->copy()->subDays($days);

                if ($at->lessThanOrEqualTo($now)) {
                    $due[] = [NotificationTemplate::BalanceDueReminder, $at];
                }
            }

            if ($booking->balance_due_at->lessThanOrEqualTo($now)) {
                $due[] = [NotificationTemplate::BalanceOverdue, $booking->balance_due_at];
            }
        }

        // 3. Pre-departure — −24h, suppressed when cancelled (already excluded
        //    by the status filter above).
        $preDeparture = $departure->copy()->subDay();

        if ($preDeparture->lessThanOrEqualTo($now)) {
            $due[] = [NotificationTemplate::PreDeparture24h, $preDeparture];
        }

        // 4. Charter agreement — −72h and −24h, suppressed once accepted or on
        //    a per-seat booking.
        if ($booking->mode->occupiesWholeVessel() && $booking->terms_accepted_at === null) {
            foreach ([72 => NotificationTemplate::CharterAgreement72h, 24 => NotificationTemplate::CharterAgreement24h] as $hours => $template) {
                $at = $departure->copy()->subHours($hours);

                if ($at->lessThanOrEqualTo($now)) {
                    $due[] = [$template, $at];
                }
            }
        }

        return $due;
    }

    /**
     * Send one reminder, subject to BKG-18.
     *
     * The **deferral** case sends nothing on this pass: 08:00 has not arrived,
     * so the next sweep will pick the same reminder up and find the window
     * open. The **drop** case is recorded, because a reminder that vanished
     * needs to be findable — BKG-18 says *"dropped and logged"* and the logging
     * half is the one that gets left out.
     */
    private function deliver(
        Booking $booking,
        Tenant $tenant,
        NotificationTemplate $template,
        Carbon $dueAt,
        Carbon $now,
    ): int {
        if (NotificationLog::alreadySent($booking->getKey(), $template, NotificationChannel::Mail)) {
            return 0;
        }

        $decision = QuietHours::decide(
            $tenant,
            $now,
            // What it warns about. For everything on this list that is the
            // departure — a "your trip is tomorrow" message deferred past the
            // trip is the failure BKG-18's second clause exists to prevent.
            $template->warnsAbout() ? $booking->starts_at_utc : null,
        );

        if (! $decision->deliver) {
            $this->notifications->dropAsTooLate($booking, $template, NotificationChannel::Mail);

            return 0;
        }

        if ($decision->wasDeferred($now)) {
            // Not yet. The next pass will find the window open, and nothing is
            // recorded — a `queued` row now would make the dedupe read suppress
            // the message it is waiting to send.
            return 0;
        }

        $this->notifications->mail($booking, $template, new GuestMail($booking, $template));

        $sent = 1;

        if ($template->usesSms()) {
            $this->notifications->sms($booking, $template, $this->smsFor($booking, $template));

            $sent++;
        }

        return $sent;
    }

    /** NTF-5's three mandatory parts, with the lead trimmed to fit. */
    private function smsFor(Booking $booking, NotificationTemplate $template): string
    {
        return SmsComposer::compose(
            lead: __("mail.{$template->value}.sms"),
            meetingPoint: (string) ($booking->product === null ? '' : ($booking->product->meetingPoint->name ?? '')),
            when: $booking->local_date->format('d/m') . ' ' . substr((string) $booking->local_time, 0, 5),
            link: route('guest.booking', ['token' => $booking->manage_token]),
            maxSegments: (int) config('kaiki.notifications.sms_max_segments', 2),
        );
    }

    /** BKG-15: the deadline is `starts_at_utc − guest_details_deadline_hours`. */
    private function guestDetailsDeadlineHours(Booking $booking): int
    {
        $product = Product::query()->find($booking->product_id);

        return $product === null ? 48 : (int) $product->guest_details_deadline_hours;
    }
}
