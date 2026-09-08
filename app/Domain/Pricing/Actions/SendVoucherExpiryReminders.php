<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Actions;

use App\Domain\Notifications\Actions\SendNotification;
use App\Enums\NotificationTemplate;
use App\Enums\VoucherStatus;
use App\Mail\GuestMail;
use App\Models\Booking;
use App\Models\Tenant;
use App\Models\Voucher;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Your credit expires soon", thirty days out and again at seven
 * (spec OPS-16, the reminder matrix at `docs/spec.md` §7).
 *
 * ## A voucher with no booking cannot be reminded, and that is not a bug
 *
 * A voucher has no email column. The address comes from the booking it was
 * issued against — which every cancellation voucher has, and which a **goodwill
 * voucher written out in the panel does not**. There is nowhere to send it.
 *
 * That is worth stating rather than silently skipping: an operator who hands
 * somebody a credit over the counter is the one who knows how to reach them,
 * and inventing a contact field so the platform could email a stranger would be
 * collecting personal data for a message nobody asked for. The panel shows the
 * expiry date; the reminder is for the vouchers that arrived by email in the
 * first place.
 *
 * ## One column, two reminders
 *
 * `expiry_reminder_sent_at` records the **most recent** reminder, and that is
 * enough to tell the two apart without a second column: the seven-day reminder
 * is due when the last one was sent *before* the seven-day window opened. A
 * voucher issued ten days before it expires therefore gets the seven-day
 * reminder and never the thirty-day one, which is right — a warning about a
 * month that has already passed is noise.
 *
 * ## Nothing is reminded about a voucher with nothing left on it
 *
 * `remaining_cents > 0`. A fully redeemed voucher keeps its expiry date and its
 * row, and telling somebody their spent credit is about to expire is a message
 * that makes them check, find nothing, and trust the next one less.
 */
final class SendVoucherExpiryReminders
{
    /** The reminder matrix, in the order they fall due. */
    private const WINDOWS = [
        30 => NotificationTemplate::VoucherExpiry30d,
        7 => NotificationTemplate::VoucherExpiry7d,
    ];

    public function __construct(private readonly SendNotification $notifications) {}

    /** @return int how many messages were put in motion */
    public function __invoke(?Carbon $now = null): int
    {
        $now ??= Carbon::now();

        $vouchers = Tenancy::withoutTenancy(static fn () => Voucher::query()
            ->where('status', VoucherStatus::Active)
            ->where('remaining_cents', '>', 0)
            ->whereNotNull('expires_at')
            ->whereNotNull('issued_for_booking_id')
            // Already gone. The sweeper will restate it; there is nothing to
            // warn anybody about.
            ->where('expires_at', '>', $now)
            // The furthest window, so the query is bounded rather than reading
            // every live voucher on the platform every night.
            ->where('expires_at', '<=', $now->copy()->addDays(array_key_first(self::WINDOWS)))
            ->get());

        $sent = 0;

        foreach ($vouchers as $voucher) {
            $sent += $this->forVoucher($voucher, $now);
        }

        return $sent;
    }

    private function forVoucher(Voucher $voucher, Carbon $now): int
    {
        $template = $this->dueTemplate($voucher, $now);

        if ($template === null) {
            return 0;
        }

        $tenant = Tenancy::withoutTenancy(
            static fn (): ?Tenant => Tenant::query()->find($voucher->tenant_id),
        );

        if (! $tenant instanceof Tenant) {
            return 0;
        }

        try {
            return (int) Tenancy::forTenant($tenant, function () use ($voucher, $template, $now): int {
                $booking = $voucher->issuedForBooking();

                if (! $booking instanceof Booking || $booking->is_test) {
                    return 0;
                }

                // `once: false` — the dedupe that matters is per **voucher**,
                // and `NotificationLog::alreadySent()` dedupes per booking. A
                // guest whose trip was cancelled twice has two vouchers, and
                // the second would never be mentioned.
                $log = $this->notifications->mail(
                    $booking,
                    $template,
                    new GuestMail($booking, $template, ['voucher' => $voucher]),
                    once: false,
                );

                if ($log === null) {
                    return 0;
                }

                $voucher->forceFill(['expiry_reminder_sent_at' => $now])->save();

                return 1;
            });
        } catch (Throwable $exception) {
            // The id and the class. A voucher's booking carries a guest's name,
            // email and telephone number, and a nightly sweep that logged the
            // row would write all three every night.
            Log::warning('vouchers.expiry_reminder_failed', [
                'voucher_id' => $voucher->getKey(),
                'tenant_id' => $voucher->tenant_id,
                'exception' => $exception::class,
            ]);

            return 0;
        }
    }

    /**
     * Which reminder is due, if either.
     *
     * The narrowest window first, so a voucher inside seven days gets the
     * seven-day message rather than the thirty-day one it also technically
     * qualifies for.
     */
    private function dueTemplate(Voucher $voucher, Carbon $now): ?NotificationTemplate
    {
        $expires = $voucher->expires_at;

        if ($expires === null) {
            return null;
        }

        foreach (array_reverse(self::WINDOWS, preserve_keys: true) as $days => $template) {
            $opensAt = $expires->copy()->subDays($days);

            if ($opensAt->greaterThan($now)) {
                continue;
            }

            $last = $voucher->expiry_reminder_sent_at;

            // Sent inside this window already, so this reminder is done. The
            // narrower window's own check is what lets the seven-day message
            // follow a thirty-day one.
            if ($last !== null && $last->greaterThanOrEqualTo($opensAt)) {
                continue;
            }

            return $template;
        }

        return null;
    }
}
