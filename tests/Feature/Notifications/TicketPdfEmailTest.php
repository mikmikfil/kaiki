<?php

declare(strict_types=1);

use App\Domain\Booking\Support\TicketAttachment;
use App\Enums\BookingStatus;
use App\Enums\NotificationTemplate;
use App\Mail\GuestMail;
use App\Models\Booking;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Mail\SentMessage;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Tests\Support\Booking\GuestPageScenario;

/*
|--------------------------------------------------------------------------
| The e-ticket PDF attached to the whole-trip emails (2026-09-23)
|--------------------------------------------------------------------------
|
| Same three messages and same rules as the inline QR codes. Most of these
| tests never start a browser: a ticket already on the (faked) disk is reused,
| and the fallback is proved by pointing Chromium at a path that does not
| exist. The one test that renders for real is skipped without a Chromium,
| like the rest of ENV-20's tests.
|
*/

const FAKE_TICKET = "%PDF-1.4\n% the one the queue already made\n%%EOF";

beforeEach(function (): void {
    Carbon::setTestNow('2026-07-03 08:00:00');
    config(['mail.default' => 'array']);
    Storage::fake((string) config('kaiki.tickets.disk', 'local'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** A Chromium that cannot start, so any render fails the way a broken host does. */
function withoutChromium(): void
{
    config(['kaiki.tickets.chrome_path' => 'C:/no/such/chrome.exe']);
}

/** A ticket the queue already rendered, after every change to the booking. */
function storeCurrentTicket(Tenant $tenant, Booking $booking): void
{
    Tenancy::forTenant($tenant, static function () use ($booking): void {
        Storage::disk((string) config('kaiki.tickets.disk', 'local'))->put('tickets/current.pdf', FAKE_TICKET);

        $booking->forceFill([
            'eticket_path' => 'tickets/current.pdf',
            'eticket_generated_at' => now()->addMinute(),
        ])->save();
    });
}

function sendTicketMail(Tenant $tenant, Booking $booking, NotificationTemplate $template): Email
{
    Tenancy::forTenant($tenant, static function () use ($booking, $template): void {
        Mail::to('guest@example.test')->send(new GuestMail($booking->refresh(), $template));
    });

    $transport = Mail::mailer('array')->getSymfonyTransport();

    if (! $transport instanceof ArrayTransport) {
        throw new LogicException('The array mailer is not an array transport.');
    }

    /** @var SentMessage $sent */
    $sent = $transport->messages()->last();

    /** @var Email $email */
    $email = $sent->getOriginalMessage();

    return $email;
}

/** @return list<DataPart> */
function pdfParts(Email $email): array
{
    return array_values(array_filter(
        $email->getAttachments(),
        static fn (DataPart $part): bool => $part->getMediaType() === 'application' && $part->getMediaSubtype() === 'pdf',
    ));
}

it('attaches the ticket to the confirmation and the day-before reminder, and says so', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(pax: 2);
    Tenancy::forTenant($tenant, static fn () => $booking->forceFill(['locale' => 'el'])->save());
    storeCurrentTicket($tenant, $booking);
    withoutChromium();

    foreach ([NotificationTemplate::BookingConfirmed, NotificationTemplate::PreDeparture24h] as $template) {
        $email = sendTicketMail($tenant, $booking, $template);
        $pdfs = pdfParts($email);

        expect($pdfs)->toHaveCount(1, $template->value)
            ->and($pdfs[0]->getFilename())->toBe('Kaiki-eisitirio-' . $booking->reference . '.pdf')
            ->and($pdfs[0]->getFilename())->not->toContain(' ')
            ->and($pdfs[0]->getPreparedHeaders()->getHeaderBody('Content-Disposition'))->toBe('attachment')
            // The stored file, reused rather than rendered again.
            ->and($pdfs[0]->getBody())->toBe(FAKE_TICKET)
            ->and($pdfs[0]->getBody())->toStartWith('%PDF')
            ->and(html_entity_decode((string) $email->getHtmlBody(), ENT_QUOTES))->toContain(__('mail.common.ticket_attached', [], 'el'))
            ->and((string) $email->getTextBody())->toContain(__('mail.common.ticket_attached', [], 'el'));

        // Never the ticket code in the name: it boards people.
        foreach (Tenancy::forTenant($tenant, static fn () => $booking->guests()->pluck('ticket_code')->all()) as $code) {
            expect($pdfs[0]->getFilename())->not->toContain((string) $code);
        }
    }
})->group('fast');

it('attaches nothing, and says nothing, where there is no ticket to give', function (string $case): void {
    [$tenant, $booking] = GuestPageScenario::booking(pax: 2, balanceCents: 4000);
    storeCurrentTicket($tenant, $booking);
    withoutChromium();

    $template = NotificationTemplate::BookingConfirmed;

    match ($case) {
        // No check-in through Kaiki: no ticket, no link, no file.
        'check-in off' => Tenancy::withoutTenancy(static fn () => Tenant::query()->whereKey($tenant->getKey())->update(['check_in_enabled' => false])),
        'cancelled' => Tenancy::forTenant($tenant, static fn () => $booking->forceFill(['status' => BookingStatus::Cancelled, 'eticket_generated_at' => now()->addMinutes(2)])->save()),
        'balance reminder' => $template = NotificationTemplate::BalanceDueReminder,
        default => Carbon::setTestNow('2026-07-05 08:00:00'),
    };

    $email = sendTicketMail($tenant, $booking, $template);

    expect(pdfParts($email))->toBe([])
        ->and((string) $email->getTextBody())->not->toContain(__('mail.common.ticket_attached', [], 'el'))
        ->and((string) $email->getHtmlBody())->not->toContain(__('mail.common.ticket_attached', [], 'el'));

    if ($case === 'check-in off') {
        expect((string) $email->getHtmlBody())->not->toContain('<img')
            ->and((string) $email->getHtmlBody())->not->toContain('/ticket');
    }
})->with(['check-in off', 'cancelled', 'balance reminder', 'sailed'])->group('fast');

it('attaches the ticket without QR boarding, and draws no inline codes', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(pax: 2);
    storeCurrentTicket($tenant, $booking);
    withoutChromium();

    // Check-in on, scanning off (product owner, 2026-09-23): the crew boards
    // from the passenger list, and the guest still gets the ticket — just
    // with no square on it, and none in the email body.
    Tenancy::withoutTenancy(static fn () => Tenant::query()->whereKey($tenant->getKey())->update(['qr_check_in_enabled' => false]));

    $email = sendTicketMail($tenant, $booking, NotificationTemplate::BookingConfirmed);
    $images = array_filter($email->getAttachments(), static fn (DataPart $part): bool => $part->getMediaType() === 'image');

    expect(pdfParts($email))->toHaveCount(1)
        ->and($images)->toBe([])
        ->and((string) $email->getHtmlBody())->not->toContain('<img')
        ->and((string) $email->getTextBody())->toContain(__('mail.common.ticket_attached', [], 'el'));
})->group('fast');

it('still sends the email when the ticket cannot be rendered, with the link and without the attachment', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(pax: 2);
    withoutChromium();
    $log = Log::spy();

    // No file on disk, and no browser to make one.
    $email = sendTicketMail($tenant, $booking, NotificationTemplate::BookingConfirmed);

    expect(pdfParts($email))->toBe([])
        ->and((string) $email->getHtmlBody())->toContain('/b/' . $booking->manage_token . '/ticket')
        ->and((string) $email->getTextBody())->not->toContain(__('mail.common.ticket_attached', [], 'el'))
        // The calendar file is unaffected.
        ->and(array_filter($email->getAttachments(), static fn (DataPart $part): bool => str_starts_with($part->getMediaSubtype(), 'calendar')))->not->toBeEmpty();

    $log->shouldHaveReceived('warning')->withArgs(
        static fn (string $message): bool => str_contains($message, 'E-ticket PDF not attached'),
    );
})->group('fast');

it('renders a fresh ticket for a change rather than attaching the old passenger list', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(pax: 2);
    storeCurrentTicket($tenant, $booking);
    withoutChromium();

    // The stored file would do for a confirmation...
    expect(pdfParts(sendTicketMail($tenant, $booking, NotificationTemplate::BookingConfirmed)))->toHaveCount(1);

    // ...but a change always renders again, and with no browser that means no
    // attachment rather than the stale one.
    expect(pdfParts(sendTicketMail($tenant, $booking, NotificationTemplate::BookingChanged)))->toBe([]);
})->group('fast');

it('treats a ticket older than a passenger change as stale', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(pax: 2);
    storeCurrentTicket($tenant, $booking);
    withoutChromium();

    // A name arrives through the details form after the ticket was made.
    Carbon::setTestNow('2026-07-03 08:05:00');
    Tenancy::forTenant($tenant, static fn () => $booking->guests()->where('position', 2)->first()?->forceFill(['full_name' => 'Νίκος Παππάς'])->save());

    expect(pdfParts(sendTicketMail($tenant, $booking, NotificationTemplate::BookingConfirmed)))->toBe([]);
})->group('fast');

it('never renders or attaches a ticket for a preview', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(pax: 2);
    withoutChromium();

    $html = Tenancy::forTenant($tenant, static fn (): string => (new GuestMail($booking->refresh(), NotificationTemplate::BookingConfirmed))->render());

    expect($html)->not->toContain(__('mail.common.ticket_attached', [], 'el'))
        ->and(Tenancy::withoutTenancy(static fn (): ?string => Booking::query()->whereKey($booking->getKey())->value('eticket_path')))->toBeNull();
})->group('fast');

it('renders and attaches a real ticket of a sensible size', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(pax: 2);

    $pdfs = pdfParts(sendTicketMail($tenant, $booking, NotificationTemplate::BookingConfirmed));

    expect($pdfs)->toHaveCount(1)
        ->and($pdfs[0]->getBody())->toStartWith('%PDF')
        // A page per passenger with a vector QR is tens of kilobytes.
        ->and(strlen($pdfs[0]->getBody()))->toBeLessThan(TicketAttachment::MAX_BYTES)
        ->and(Tenancy::withoutTenancy(static fn (): ?string => Booking::query()->whereKey($booking->getKey())->value('eticket_path')))->not->toBeNull();
})->skip(
    fn (): bool => ! is_string(config('kaiki.tickets.chrome_path')) || config('kaiki.tickets.chrome_path') === '',
    'ENV-20: rendering a ticket needs a Chromium binary. Set KAIKI_CHROME_PATH in .env, '
    . 'or run this in CI where it is always present.',
)->group('chromium');
