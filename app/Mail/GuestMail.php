<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Booking\Support\BookingCalendarInvite;
use App\Domain\Branding\Actions\GetBrandPayload;
use App\Domain\Notifications\Actions\SendNotification;
use App\Enums\NotificationTemplate;
use App\Models\Booking;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Every transactional email a guest receives (spec NTF-1, NTF-4, NTF-6, NTF-7).
 *
 * ## One mailable, many templates
 *
 * The alternative is fifteen classes that differ by a Blade path and a subject
 * key. Each would carry its own copy of the locale chain, its own copy of the
 * brand lookup, and its own opportunity to forget the plain-text alternative —
 * and NTF-6 makes that alternative mandatory. One class means one place all
 * three are right.
 *
 * ## The locale is set here, not assumed
 *
 * NTF-4's chain lives in {@see SendNotification::localeFor()}, and this calls it
 * rather than reading `app()->getLocale()`. A reminder sweep runs on a worker
 * with no request behind it: whatever locale that worker happens to hold is the
 * locale of the *last* message it sent, which is how a Greek guest gets an
 * English reminder on a busy morning.
 *
 * ## Both bodies, always
 *
 * NTF-6: *"MUST include a plain-text alternative."* Not a nicety — a plain-text
 * part is what makes the message readable in a client that blocks HTML, what
 * keeps it out of a spam folder, and what a screen reader gets. It is set here
 * so no template can ship without one.
 */
class GuestMail extends Mailable
{
    public function __construct(
        public readonly Booking $booking,
        public readonly NotificationTemplate $template,
        /** @var array<string, mixed> */
        public readonly array $extra = [],
    ) {
        // NTF-4, applied before the subject line is translated.
        $this->locale(SendNotification::localeFor($booking));
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __("mail.{$this->template->value}.subject", [
                'reference' => $this->booking->reference,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.booking.html',
            // NTF-6's plain-text alternative, on every message by construction.
            text: 'mail.booking.text',
            with: [
                'booking' => $this->booking,
                'template' => $this->template,
                'brand' => $this->brand(),
                'extra' => $this->extra,
            ],
        );
    }

    /**
     * The trip as a calendar file (product owner, 2026-09-18).
     *
     * The body already carries two "add to calendar" links, and this is a third
     * route to the same thing because it is the shortest one there is: Gmail and
     * Apple Mail both read a `text/calendar` part and put an "Add to calendar"
     * strip above the message — no link to tap, no download, no browser. A guest
     * who never scrolls past the first screen still gets it.
     *
     * `method=PUBLISH` in the MIME type, matching the file's own `METHOD`.
     * Without it some clients read the part as a meeting invitation and draw
     * accept/decline buttons on a trip that is already paid for.
     *
     * Only on the three messages that are the whole trip. A balance reminder
     * carrying a calendar file would be offering a second copy of a morning the
     * guest's calendar already holds.
     *
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        if (! $this->template->carriesWholeTrip()) {
            return [];
        }

        $tenant = Tenancy::withoutTenancy(
            fn (): ?Tenant => Tenant::query()->find($this->booking->tenant_id),
        );

        if (! $tenant instanceof Tenant) {
            return [];
        }

        $booking = $this->booking;
        $locale = SendNotification::localeFor($booking);

        // Built inside the tenant, so the meeting point the file names is one
        // the relation can actually see — the same reason `brand()` below does.
        /** @var array{0: string, 1: string}|null $file */
        $file = Tenancy::forTenant($tenant, static function () use ($booking, $locale): ?array {
            $invite = BookingCalendarInvite::for($booking, $locale);

            return $invite->isAvailable() ? [$invite->ics(), $invite->filename()] : null;
        });

        if ($file === null) {
            return [];
        }

        return [
            Attachment::fromData(static fn (): string => $file[0], $file[1])
                ->withMime('text/calendar; charset=UTF-8; method=PUBLISH'),
        ];
    }

    /**
     * The operator's own colours and name.
     *
     * BRD-1 reaches the inbox too: an email that looked like this platform
     * rather than like the operator the guest booked with is an email they
     * distrust, and a confirmation nobody trusts is a phone call.
     *
     * @return array<string, mixed>
     */
    private function brand(): array
    {
        $tenant = Tenancy::withoutTenancy(
            fn (): ?Tenant => Tenant::query()->find($this->booking->tenant_id),
        );

        if (! $tenant instanceof Tenant) {
            return [];
        }

        return Tenancy::forTenant($tenant, fn (): array => app(GetBrandPayload::class)(
            $tenant,
            SendNotification::localeFor($this->booking),
        ));
    }
}
