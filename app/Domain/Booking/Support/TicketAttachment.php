<?php

declare(strict_types=1);

namespace App\Domain\Booking\Support;

use App\Domain\Booking\Actions\GenerateETicket;
use App\Enums\NotificationTemplate;
use App\Listeners\Booking\RegenerateETicketOnChange;
use App\Models\Booking;
use App\Models\Tenant;
use App\Support\Tenancy;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The e-ticket PDF, attached to the emails that carry the whole trip
 * (product owner, 2026-09-23).
 *
 * ## The same three messages, whenever the booking has a ticket
 *
 * The confirmation, a change and the day-before reminder, and only when the
 * email's own ticket link would be there: the operator checks people in
 * through Kaiki ({@see Tenant::usesCheckIn()}), the booking is ticketed, and
 * the trip has not sailed. QR boarding is **not** a condition (product owner,
 * 2026-09-23): without it the PDF is the same ticket with no square on it, the
 * way `GenerateETicket` already draws it, while the inline QR codes in the
 * body follow {@see BoardingPasses} and stay off. A PDF in the inbox of a
 * cancelled booking is a boarding pass for a seat that is not there; the link
 * in the email already refuses one, and so does this.
 *
 * ## Reused when current, rendered again when not
 *
 * `eticket_hash` is a hash of the *file*, not of what went into it, so it
 * cannot say whether the ticket still matches the booking. What can is time:
 * the file is stale when it is missing, when any passenger row changed after
 * it was made (names arrive through the details form after confirmation), or
 * when the booking itself did. A change message always renders a fresh one —
 * {@see RegenerateETicketOnChange} runs on the same
 * event in parallel, and a guest told "your booking changed" must not get the
 * old passenger list attached because the other job had not finished yet.
 *
 * The booking row's own `updated_at` moves when `GenerateETicket` stores the
 * path it just wrote, a moment after `eticket_generated_at`; the few seconds of
 * slack keep that save from marking every ticket stale the instant it is made.
 *
 * ## Never the reason an email does not go
 *
 * Chromium that will not start, a timeout, a full disk: each is logged and the
 * email goes without the attachment. The ticket link in the message still
 * works (and renders on demand), so a guest loses a convenience, not the
 * ticket. A stale file is not attached as a fallback — an out-of-date passenger
 * list at the gangway is worse than none.
 */
final class TicketAttachment
{
    /**
     * A ticket is a page per passenger, about 100 KB each with Chromium's
     * embedded fonts (205 KB for two, measured 2026-09-23). Anything near this
     * is not a ticket but a fault, and mail providers start refusing messages
     * not far above.
     */
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** See the class docblock: the save of `eticket_path` after the render. */
    private const SLACK_SECONDS = 10;

    /**
     * The PDF bytes and the file name, or null when there should be none or it
     * could not be made.
     *
     * @return array{bytes: string, filename: string}|null
     */
    public static function for(Booking $booking, NotificationTemplate $template): ?array
    {
        if (! $template->carriesWholeTrip() || ! $booking->exists) {
            return null;
        }

        $tenant = Tenancy::withoutTenancy(
            static fn (): ?Tenant => Tenant::query()->find($booking->tenant_id),
        );

        // Whenever the booking has a ticket at all — with or without QR
        // boarding, whose absence only leaves the square off the PDF.
        if (! $tenant instanceof Tenant
            || ! $tenant->usesCheckIn()
            || ! $booking->status->hasTicket()
            || $booking->starts_at_utc->isPast()) {
            return null;
        }

        try {
            /** @var string|null $bytes */
            $bytes = Tenancy::forTenant($tenant, static function () use ($booking, $template): ?string {
                $disk = self::disk();
                $path = $booking->eticket_path;

                if ($path === null
                    || ! $disk->exists($path)
                    || $template === NotificationTemplate::BookingChanged
                    || self::isStale($booking)) {
                    $path = app(GenerateETicket::class)($booking);
                }

                return $disk->get($path);
            });
        } catch (Throwable $exception) {
            report($exception);
            Log::warning('E-ticket PDF not attached; the email goes without it.', [
                'booking_id' => $booking->getKey(),
                'template' => $template->value,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! is_string($bytes) || ! str_starts_with($bytes, '%PDF') || strlen($bytes) > self::MAX_BYTES) {
            Log::warning('E-ticket PDF not attached: not a PDF, or too large to email.', [
                'booking_id' => $booking->getKey(),
                'template' => $template->value,
                'bytes' => is_string($bytes) ? strlen($bytes) : null,
            ]);

            return null;
        }

        return [
            'bytes' => $bytes,
            'filename' => self::filename($booking),
        ];
    }

    /**
     * `Kaiki-eisitirio-KAI-7F3K2.pdf`: Latin, no spaces, so no mail client
     * mangles it, and the reference — the string a guest recognises in a
     * downloads folder — never the ticket code, which boards people.
     */
    public static function filename(Booking $booking): string
    {
        return 'Kaiki-eisitirio-' . preg_replace('/[^A-Za-z0-9-]/', '', (string) $booking->reference) . '.pdf';
    }

    private static function isStale(Booking $booking): bool
    {
        // The column has no cast on the model, so it arrives as a string.
        $made = $booking->getAttribute('eticket_generated_at');

        if (is_string($made) && $made !== '') {
            $made = Carbon::parse($made);
        }

        if (! $made instanceof CarbonInterface) {
            return true;
        }

        if ($booking->updated_at instanceof CarbonInterface
            && $booking->updated_at->gt($made->copy()->addSeconds(self::SLACK_SECONDS))) {
            return true;
        }

        $lastGuestChange = $booking->guests()->max('updated_at');

        return $lastGuestChange !== null && $made->lt($lastGuestChange);
    }

    private static function disk(): Filesystem
    {
        return Storage::disk((string) config('kaiki.tickets.disk', 'local'));
    }
}
