<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Actions;

use App\Domain\Notifications\Support\SmsComposer;
use App\Domain\Notifications\Support\SmsGatewayResolver;
use App\Enums\NotificationChannel;
use App\Enums\NotificationProvider;
use App\Enums\NotificationStatus;
use App\Enums\NotificationTemplate;
use App\Models\Booking;
use App\Models\NotificationLog;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The one place a message leaves the building (spec NTF-3, NTF-4, BKG-16).
 *
 * ## The log row is written first, and that is the whole design
 *
 * NTF-3 wants every send recorded. Recording it **after** a successful send
 * would produce a log of the sends that worked — which is the log nobody needs.
 * So the row is written in `queued` before the provider is touched, and updated
 * with what came back. A crash in between leaves a `queued` row that says
 * exactly what was about to happen.
 *
 * ## The locale is the guest's, and never the operator's
 *
 * NTF-4: the **booking** locale, falling back to the tenant default, falling
 * back to `en`. Not `app()->getLocale()` — a reminder sweep runs on a worker
 * with no request behind it, and a message composed from a panel session would
 * send an operator's Greek to a guest who booked in English.
 *
 * {@see self::localeFor()} is that chain, in one place, because three callers
 * implementing it produce three different answers on the booking that has none.
 *
 * ## Idempotency is a question asked of the log
 *
 * BKG-16's *"idempotent per booking per reminder type"*. The check and the
 * write happen inside the same call, so two overlapping sweeps produce one
 * message — and a `failed` row is deliberately not counted, because a reminder
 * that failed has not been sent.
 */
final class SendNotification
{
    public function __construct(private readonly SmsGatewayResolver $gateways) {}

    /**
     * Send an email, recording the attempt.
     *
     * @param  bool  $once  BKG-16's dedupe; false for messages that fire on an
     *                      event rather than on a schedule
     */
    public function mail(
        Booking $booking,
        NotificationTemplate $template,
        Mailable $mailable,
        bool $once = true,
    ): ?NotificationLog {
        if ($once && NotificationLog::alreadySent($booking->getKey(), $template, NotificationChannel::Mail)) {
            return null;
        }

        $log = $this->record($booking, $template, NotificationChannel::Mail, $booking->guest_email);

        try {
            Mail::to($booking->guest_email)->send($mailable);
        } catch (Throwable $exception) {
            return $this->markFailed($log, $exception);
        }

        $log->forceFill([
            'status' => NotificationStatus::Sent,
            'provider' => NotificationProvider::Postmark,
            'sent_at' => now(),
        ])->save();

        return $log;
    }

    /**
     * Send an SMS, recording the attempt and what it will cost.
     *
     * The segment count is stored even when the provider reports no price:
     * NTF-5 requires the operator to be **warned about segment count**, and the
     * only place that warning can survive the send is the log row.
     */
    public function sms(
        Booking $booking,
        NotificationTemplate $template,
        string $body,
        bool $once = true,
    ): ?NotificationLog {
        if ($once && NotificationLog::alreadySent($booking->getKey(), $template, NotificationChannel::Sms)) {
            return null;
        }

        $phone = trim((string) $booking->guest_phone);

        if ($phone === '') {
            // BKG-13.3: *"if the tenant has SMS enabled **and the phone is
            // valid**"*. Nothing is logged, because nothing was attempted —
            // a row saying we failed to text a guest who gave no number would
            // fill the failure feed with the operator's own form design.
            return null;
        }

        $tenant = self::tenantOf($booking);
        $gateway = $this->gateways->forTenant($tenant);

        $log = $this->record($booking, $template, NotificationChannel::Sms, $phone);

        $log->forceFill([
            'provider' => $gateway->provider(),
            // Recorded before the send: this is the number NTF-5 wants the
            // operator warned about, and it is a property of the message rather
            // than of the outcome.
            'subject' => sprintf(
                '%s · %d segment(s)',
                SmsComposer::encoding($body),
                SmsComposer::segments($body),
            ),
        ])->save();

        $result = $gateway->send($phone, $body);

        if (! $result->sent) {
            $log->forceFill([
                'status' => NotificationStatus::Failed,
                'error_message' => mb_substr((string) $result->error, 0, 500),
            ])->save();

            return $log;
        }

        $log->forceFill([
            'status' => NotificationStatus::Sent,
            'provider_ref' => $result->reference,
            'cost_cents' => $result->costCents,
            'sent_at' => now(),
        ])->save();

        return $log;
    }

    /**
     * BKG-18's decision, recorded when it says no.
     *
     * *"…dropped **and logged**."* The logging half is why this lives here
     * rather than in the caller: a reminder that vanished because 08:00 was
     * after the departure has to leave a row saying so, or an operator asking
     * "why did my guest not get the text" has nothing to read.
     */
    public function dropAsTooLate(Booking $booking, NotificationTemplate $template, NotificationChannel $channel): NotificationLog
    {
        $log = $this->record(
            $booking,
            $template,
            $channel,
            $channel === NotificationChannel::Sms ? (string) $booking->guest_phone : $booking->guest_email,
        );

        $log->forceFill([
            'status' => NotificationStatus::Failed,
            'error_message' => 'quiet_hours_would_deliver_after_the_event',
        ])->save();

        return $log;
    }

    /** The row, in `queued`, before anything is attempted. */
    private function record(
        Booking $booking,
        NotificationTemplate $template,
        NotificationChannel $channel,
        string $to,
    ): NotificationLog {
        $log = new NotificationLog;

        $log->forceFill([
            'booking_id' => $booking->getKey(),
            'departure_id' => $booking->departure_id,
            'channel' => $channel,
            'template' => $template,
            'locale' => self::localeFor($booking),
            'to' => mb_substr($to, 0, 190),
            'status' => NotificationStatus::Queued,
        ])->save();

        return $log;
    }

    private function markFailed(NotificationLog $log, Throwable $exception): NotificationLog
    {
        // The class and the ids. An exception message from a mail transport
        // carries the recipient and sometimes the body (SEC-9).
        Log::warning('notifications.send_failed', [
            'notification_log_id' => $log->getKey(),
            'booking_id' => $log->booking_id,
            'tenant_id' => $log->tenant_id,
            'exception' => $exception::class,
        ]);

        $log->forceFill([
            'status' => NotificationStatus::Failed,
            'provider' => NotificationProvider::Postmark,
            'error_message' => mb_substr($exception::class, 0, 500),
        ])->save();

        return $log;
    }

    /**
     * NTF-4's chain: booking, then tenant default, then `en`.
     *
     * Public and static so the mailables and the SMS composers read it rather
     * than each deciding for themselves — the booking with a blank locale is
     * where three implementations disagree.
     */
    public static function localeFor(Booking $booking): string
    {
        $supported = ['el', 'en'];

        if (in_array($booking->locale, $supported, true)) {
            return $booking->locale;
        }

        $tenant = self::tenantOf($booking);

        if ($tenant !== null && in_array($tenant->default_locale, $supported, true)) {
            return $tenant->default_locale;
        }

        return 'en';
    }

    private static function tenantOf(Booking $booking): ?Tenant
    {
        return Tenancy::withoutTenancy(
            static fn (): ?Tenant => Tenant::query()->find($booking->tenant_id),
        );
    }
}
