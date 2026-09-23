<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Booking\Support\BookingCalendarInvite;
use App\Domain\Booking\Support\TicketAttachment;
use App\Domain\Branding\Actions\GetBrandPayload;
use App\Domain\Notifications\Actions\SendNotification;
use App\Enums\NotificationTemplate;
use App\Mail\Support\OperatorSender;
use App\Models\Booking;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Contracts\Mail\Factory;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\SentMessage;

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
    /**
     * The e-ticket to attach, found by {@see send()}; null on a preview.
     *
     * @var array{bytes: string, filename: string}|null
     */
    private ?array $ticketPdf = null;

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
        $tenant = $this->tenant();

        return new Envelope(
            // From the platform, in the operator's name; answered to the
            // operator ({@see OperatorSender} says why not their own SMTP).
            from: OperatorSender::from($tenant),
            replyTo: OperatorSender::replyTo($tenant),
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
                // «Το εισιτήριο είναι και συνημμένο σε PDF», only when it is.
                'ticketPdfAttached' => $this->ticketPdf !== null,
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

        $attachments = [];

        if ($file !== null) {
            $attachments[] = Attachment::fromData(static fn (): string => $file[0], $file[1])
                ->withMime('text/calendar; charset=UTF-8; method=PUBLISH');
        }

        // The e-ticket, when send() found one to give ({@see TicketAttachment}).
        if ($this->ticketPdf !== null) {
            $pdf = $this->ticketPdf;

            $attachments[] = Attachment::fromData(static fn (): string => $pdf['bytes'], $pdf['filename'])
                ->withMime('application/pdf');
        }

        return $attachments;
    }

    /**
     * The e-ticket PDF is resolved here, on a real send, and nowhere else
     * (product owner, 2026-09-23).
     *
     * Not in `attachments()` alone, for two reasons. `Mailable::render()` — the
     * notification log's preview, the email gallery — calls `attachments()`
     * too, and a preview must not start Chromium or write a file. And the body
     * says «the ticket is also attached» only when it is: `content()` and
     * `attachments()` are both read inside `parent::send()`, after this line,
     * so the sentence and the file cannot disagree.
     *
     * A PDF that cannot be made is logged and left out; the email still goes.
     *
     * @param  Factory|Mailer  $mailer
     * @return SentMessage|null
     */
    public function send($mailer)
    {
        $this->ticketPdf = TicketAttachment::for($this->booking, $this->template);

        return parent::send($mailer);
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
        $tenant = $this->tenant();

        if (! $tenant instanceof Tenant) {
            return [];
        }

        return Tenancy::forTenant($tenant, fn (): array => app(GetBrandPayload::class)(
            $tenant,
            SendNotification::localeFor($this->booking),
        ));
    }

    /** The booking's operator, read past the tenant scope — a queue has none. */
    private function tenant(): ?Tenant
    {
        return Tenancy::withoutTenancy(
            fn (): ?Tenant => Tenant::query()->find($this->booking->tenant_id),
        );
    }
}
