<?php

declare(strict_types=1);

/**
 * Re-render the emails inside `all-emails.html` from today's templates.
 *
 * Run it with `php docs/mockups/refresh-emails.php` while the local database
 * holds the demo data. It boots the application, renders each listed message
 * through the real `GuestMail` — the same class the queue sends — and writes
 * the result back into the gallery's `const DATA` blob. Everything else in the
 * page (the CSS, the index, the notes about when each message goes out) is
 * hand-written and is left exactly as it is.
 *
 * ## Why only some of the twenty
 *
 * The messages listed in {@see REFRESHABLE} are the ones that need nothing but
 * a booking. The rest — a voucher about to expire, a quote, a weather choice —
 * are rendered with an `extra` payload that the sweep that sends them builds,
 * and a mockup that invented one would show the operator a message the
 * platform never sends. Add a key here when its payload is worth pinning down.
 *
 * The three in the list today are also the three that carry the trip as a
 * calendar file (2026-09-18), which is what this script was written for: after
 * a change to what the guest receives, the gallery Mike opens should show it
 * rather than last week's render.
 */

use App\Domain\Booking\Support\BookingCalendarInvite;
use App\Enums\NotificationTemplate;
use App\Mail\GuestMail;
use App\Models\Booking;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

require __DIR__ . '/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/** Gallery key => the template it renders. */
const REFRESHABLE = [
    'booking_confirmed' => NotificationTemplate::BookingConfirmed,
    'booking_changed' => NotificationTemplate::BookingChanged,
    'pre_departure_24h' => NotificationTemplate::PreDeparture24h,
];

$page = __DIR__ . '/all-emails.html';
$html = (string) file_get_contents($page);

if (! preg_match('/const DATA = (\{.*?\});\n/s', $html, $match)) {
    fwrite(STDERR, "The page no longer carries a `const DATA` blob.\n");
    exit(1);
}

/** @var array{groups: list<array{name: string, items: list<array<string, mixed>>}>, sms: list<array<string, string>>, total: int} $data */
$data = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);

$rendered = 0;

foreach ($data['groups'] as $g => $group) {
    foreach ($group['items'] as $i => $item) {
        $template = REFRESHABLE[$item['key']] ?? null;

        if (! $template instanceof NotificationTemplate) {
            continue;
        }

        // The booking each message was rendered from the first time, so the
        // gallery keeps showing the same trip and a diff is about the template
        // rather than about which demo row was picked today.
        if (! preg_match('/KAI-[A-Z0-9]+/', (string) $item['subject_el'], $reference)) {
            fwrite(STDERR, "No booking reference in the subject of {$item['key']}.\n");
            exit(1);
        }

        $booking = Tenancy::withoutTenancy(
            static fn (): ?Booking => Booking::query()->where('reference', $reference[0])->first(),
        );

        if (! $booking instanceof Booking) {
            fwrite(STDERR, "The demo database has no booking {$reference[0]}. Seed it first.\n");
            exit(1);
        }

        $tenant = Tenancy::withoutTenancy(
            static fn (): ?Tenant => Tenant::query()->find($booking->tenant_id),
        );

        if (! $tenant instanceof Tenant) {
            fwrite(STDERR, "Booking {$reference[0]} has no operator.\n");
            exit(1);
        }

        /** @var array{html: string, text: string, el: string, en: string, ics: string|null} $parts */
        $parts = Tenancy::forTenant($tenant, static function () use ($booking, $template): array {
            $mail = new GuestMail($booking, $template);

            app()->setLocale('el');

            $text = view('mail.booking.text', [
                'booking' => $booking,
                'template' => $template,
                'brand' => [],
                'extra' => [],
            ])->render();

            $subject = static fn (string $locale): string => __(
                "mail.{$template->value}.subject",
                ['reference' => $booking->reference],
                $locale,
            );

            $invite = BookingCalendarInvite::for($booking, 'el');

            return [
                'html' => $mail->render(),
                'text' => $text,
                'el' => $subject('el'),
                'en' => $subject('en'),
                // What the guest's mail client shows above the message, and the
                // one part of this that no screenshot of the body would reveal.
                'ics' => $template->carriesWholeTrip() && $invite->isAvailable() ? $invite->filename() : null,
            ];
        });

        $data['groups'][$g]['items'][$i] = array_merge($item, [
            'html' => $parts['html'],
            'text' => $parts['text'],
            'subject_el' => $parts['el'],
            'subject_en' => $parts['en'],
            'attachment' => $parts['ics'],
            'error' => null,
        ]);

        $rendered++;

        echo "rendered {$item['key']} from {$reference[0]}" . ($parts['ics'] !== null ? " (+ {$parts['ics']})" : '') . "\n";
    }
}

$blob = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

file_put_contents($page, str_replace($match[0], "const DATA = {$blob};\n", $html));

echo "{$rendered} of {$data['total']} messages re-rendered into docs/mockups/all-emails.html\n";
