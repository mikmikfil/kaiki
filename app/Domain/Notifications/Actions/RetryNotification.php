<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Actions;

use App\Domain\Notifications\Support\SmsComposer;
use App\Enums\NotificationChannel;
use App\Mail\GuestMail;
use App\Models\Booking;
use App\Models\NotificationLog;

/**
 * BKG-14's retry button, behind the panel (spec BKG-14, NTF-3).
 *
 * > *Failed listeners appear in the operator panel with a plain-Greek
 * > explanation and a **retry button**.*
 *
 * ## It composes again rather than re-sending the old body
 *
 * The message is rebuilt from the booking as it stands **now**. A retry that
 * replayed a stored body would send a guest the balance they owed a week ago,
 * or a meeting point the operator has since corrected — and the reason the
 * original failed is often that something needed correcting.
 *
 * ## It writes a new row rather than flipping the old one
 *
 * The log records **attempts**. Turning a `failed` row into `sent` would erase
 * the fact that the first attempt failed, which is exactly the history an
 * operator asking *"why did the guest never hear from us"* needs — and which
 * `NotificationLog::alreadySent()` deliberately does not count, so the retry is
 * allowed through.
 */
final class RetryNotification
{
    public function __construct(private readonly SendNotification $notifications) {}

    /** @return bool false when there is nothing left to retry against */
    public function __invoke(NotificationLog $log): bool
    {
        $booking = $log->booking;

        if (! $booking instanceof Booking) {
            // A tenant-level message, or a booking that has since been purged.
            // Nothing to rebuild from, and inventing a recipient would be worse
            // than leaving the row in the feed.
            return false;
        }

        if ($log->channel === NotificationChannel::Sms) {
            $this->notifications->sms(
                $booking,
                $log->template,
                SmsComposer::compose(
                    lead: __("mail.{$log->template->value}.sms"),
                    meetingPoint: (string) ($booking->product === null ? '' : ($booking->product->meetingPoint->name ?? '')),
                    when: $booking->local_date->format('d/m') . ' ' . substr((string) $booking->local_time, 0, 5),
                    link: route('guest.booking', ['token' => $booking->manage_token]),
                    maxSegments: (int) config('kaiki.notifications.sms_max_segments', 2),
                ),
                // The dedupe is what the operator is deliberately overriding by
                // pressing the button, so it is off — and the failed row would
                // not have blocked it anyway.
                once: false,
            );

            return true;
        }

        $this->notifications->mail(
            $booking,
            $log->template,
            new GuestMail($booking, $log->template),
            once: false,
        );

        return true;
    }
}
