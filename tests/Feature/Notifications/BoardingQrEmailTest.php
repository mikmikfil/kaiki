<?php

declare(strict_types=1);

use App\Domain\Booking\Support\TicketQr;
use App\Enums\BookingStatus;
use App\Enums\NotificationTemplate;
use App\Mail\GuestMail;
use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Mail\SentMessage;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Tests\Support\Booking\GuestPageScenario;

/*
|--------------------------------------------------------------------------
| The boarding QR inside the email (product owner, 2026-09-23)
|--------------------------------------------------------------------------
|
| One PNG per passenger, carried inside the message as a `cid:` part — never a
| URL that serves a boarding code — and only where the ticket PDF and the
| booking page would show one: QR boarding on, a ticketed booking, a trip not
| yet sailed, and a message that carries the whole trip.
|
*/

// The scenario's departure is on 4 July; the codes stop once a trip has sailed.
beforeEach(function (): void {
    Carbon::setTestNow('2026-07-03 08:00:00');
    // These sends also attach the e-ticket PDF (TicketPdfEmailTest); keep any
    // render off the real disk and away from Chromium.
    Storage::fake((string) config('kaiki.tickets.disk', 'local'));
    config(['kaiki.tickets.chrome_path' => 'C:/no/such/chrome.exe']);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Send one message through the `array` transport and hand back what left.
 */
function sendGuestMail(Tenant $tenant, Booking $booking, NotificationTemplate $template): Email
{
    config(['mail.default' => 'array']);

    Tenancy::forTenant($tenant, static function () use ($booking, $template): void {
        Mail::to('guest@example.test')->send(new GuestMail($booking->refresh(), $template));
    });

    $transport = Mail::mailer('array')->getSymfonyTransport();

    if (! $transport instanceof ArrayTransport) {
        throw new LogicException('The array mailer is not an array transport.');
    }

    /** @var SentMessage $sent */
    $sent = $transport->messages()->last();

    $email = $sent->getOriginalMessage();

    expect($email)->toBeInstanceOf(Email::class);

    /** @var Email $email */
    return $email;
}

/** @return list<DataPart> */
function inlinePngs(Email $email): array
{
    return array_values(array_filter(
        $email->getAttachments(),
        static fn (DataPart $part): bool => $part->getMediaType() === 'image'
            && $part->getMediaSubtype() === 'png'
            && $part->getPreparedHeaders()->getHeaderBody('Content-Disposition') === 'inline',
    ));
}

/** @return list<BookingGuest> */
function guestsOf(Tenant $tenant, Booking $booking): array
{
    return Tenancy::forTenant($tenant, static fn (): array => $booking->guests()->orderBy('position')->get()->all());
}

it('embeds one PNG per passenger, inline, carrying the ticket payload', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(pax: 3);

    $email = sendGuestMail($tenant, $booking, NotificationTemplate::BookingConfirmed);
    $pngs = inlinePngs($email);
    $guests = guestsOf($tenant, $booking);

    expect($pngs)->toHaveCount(3);

    foreach ($pngs as $index => $part) {
        $bytes = $part->getBody();

        // A real PNG a mail client can draw: the eight magic bytes, and GD
        // reads it back as a square with white corners — the quiet zone a
        // phone camera needs to find the code at all.
        expect(substr($bytes, 0, 8))->toBe("\x89PNG\r\n\x1a\n");

        $image = imagecreatefromstring($bytes);
        expect($image)->not->toBeFalse();

        /** @var GdImage $image */
        $size = imagesx($image);
        expect(imagesy($image))->toBe($size)
            ->and($size)->toBeGreaterThanOrEqual(200)
            ->and(imagecolorat($image, 1, 1) & 0xFFFFFF)->toBe(0xFFFFFF)
            ->and(imagecolorat($image, $size - 2, $size - 2) & 0xFFFFFF)->toBe(0xFFFFFF);

        // The payload is exactly TicketQr's — the boarding URL with this
        // passenger's `ticket_code` — drawn by the same generator. The render
        // is deterministic, so equal bytes mean an equal payload.
        expect($bytes)->toBe(TicketQr::png(TicketQr::payloadFor($guests[$index])))
            ->and(TicketQr::payloadFor($guests[$index]))->toContain('/app/boarding?ticket=' . $guests[$index]->ticket_code);

        // Referenced from the HTML by its content id, so nothing is fetched.
        expect((string) $email->getHtmlBody())->toContain('cid:' . $part->getContentId());
    }

    // Three different codes, not one image three times.
    expect(array_unique(array_map(static fn (DataPart $part): string => $part->getBody(), $pngs)))->toHaveCount(3);
})->group('fast');

it('labels each code with its passenger, and «Επιβάτης N» before a name is given', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(pax: 2);

    Tenancy::forTenant($tenant, static fn () => $booking->guests()->where('position', 1)->update(['full_name' => 'Ελένη Παππά']));
    Tenancy::forTenant($tenant, static fn () => $booking->forceFill(['locale' => 'el'])->save());

    $html = html_entity_decode((string) sendGuestMail($tenant, $booking, NotificationTemplate::BookingConfirmed)->getHtmlBody(), ENT_QUOTES);

    expect($html)
        ->toContain('alt="' . __('mail.common.boarding_alt', ['name' => 'Ελένη Παππά'], 'el') . '"')
        ->toContain('alt="' . __('mail.common.boarding_alt', ['name' => 'Επιβάτης 2'], 'el') . '"')
        ->toContain(__('mail.common.boarding_heading', [], 'el'))
        // Shown at a size a phone scans comfortably, whatever it was drawn at.
        ->toContain('width="200" height="200"');
})->group('fast');

it('tells the plain-text reader where the codes are', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(pax: 2);

    Tenancy::forTenant($tenant, static fn () => $booking->forceFill(['locale' => 'el'])->save());

    $text = (string) sendGuestMail($tenant, $booking, NotificationTemplate::BookingConfirmed)->getTextBody();

    expect($text)->toContain(trans_choice('mail.common.boarding_text', 2, [], 'el'))
        ->and($text)->toContain('/ticket');
})->group('fast');

it('carries no code when the operator does not board by QR', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(pax: 2);

    // Set by the platform on `/admin` (BKG-20 as amended). The PDF drops its
    // square on the same switch, and so does the booking page.
    Tenancy::withoutTenancy(static fn () => Tenant::query()->whereKey($tenant->getKey())->update(['qr_check_in_enabled' => false]));

    $email = sendGuestMail($tenant, $booking, NotificationTemplate::BookingConfirmed);

    expect(inlinePngs($email))->toBe([])
        ->and((string) $email->getHtmlBody())->not->toContain('<img')
        ->and((string) $email->getTextBody())->not->toContain(trans_choice('mail.common.boarding_text', 2, [], 'el'))
        // The ticket itself is still there: the passenger list boards them.
        ->and((string) $email->getHtmlBody())->toContain('/ticket');
})->group('fast');

it('carries no code on a message that is not the whole trip, or on a booking with no ticket', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(pax: 2, balanceCents: 4000);

    // A balance reminder is about money; the codes belong to the three
    // messages that carry the trip.
    expect(inlinePngs(sendGuestMail($tenant, $booking, NotificationTemplate::BalanceDueReminder)))->toBe([]);

    // A cancelled booking has no ticket, and a code in its inbox would scan.
    Tenancy::forTenant($tenant, static fn () => $booking->forceFill(['status' => BookingStatus::Cancelled])->save());

    expect(inlinePngs(sendGuestMail($tenant, $booking, NotificationTemplate::BookingConfirmed)))->toBe([]);
})->group('fast');

it('embeds the codes on the change message and the day-before reminder too', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(pax: 2);

    expect(inlinePngs(sendGuestMail($tenant, $booking, NotificationTemplate::BookingChanged)))->toHaveCount(2)
        ->and(inlinePngs(sendGuestMail($tenant, $booking, NotificationTemplate::PreDeparture24h)))->toHaveCount(2);
})->group('fast');
