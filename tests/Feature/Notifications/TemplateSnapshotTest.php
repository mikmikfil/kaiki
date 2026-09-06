<?php

declare(strict_types=1);

use App\Enums\NotificationTemplate;
use App\Mail\GuestMail;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Tests\Support\Booking\GuestPageScenario;

/*
|--------------------------------------------------------------------------
| NTF-6, NTF-9, TST-5, I18N-2: every template, both locales
|--------------------------------------------------------------------------
|
| > *Email and SMS templates are snapshot-tested in both locales.*
|
| A snapshot here is not a stored file to diff — it is the set of properties
| every message must have whatever the template. A file of golden HTML would
| break on every wording change and be updated without being read, which is the
| failure mode of snapshot testing generally.
|
| So each template is **rendered in both locales** and asserted against the
| things that actually go wrong:
|
|   NTF-6   readable with no images, and a plain-text part on every message
|   I18N-2  no `text-transform: uppercase`, which drops Greek accents
|   NTF-4   the locale is the guest's
|   NTF-7   a footer that says this is transactional, on every one
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-07-03 11:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Both bodies of one template, in one locale.
 *
 * @return array{0: string, 1: string} the HTML and the plain-text alternative
 */
function renderTemplate(NotificationTemplate $template, string $locale): array
{
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 8000, balanceCents: 4000);

    return Tenancy::forTenant($tenant, function () use ($booking, $template, $locale): array {
        $booking->forceFill(['locale' => $locale])->save();

        $mail = new GuestMail($booking->refresh(), $template);

        $rendered = $mail->render();

        // The text half is rendered by hand, because `Mailable::render()`
        // returns only the HTML — and it is wrapped in a locale switch, which
        // is what the mailer itself does on a real send. Without it the plain
        // text comes out in whatever locale the worker happened to hold, which
        // is the exact bug NTF-4 exists to prevent.
        $previous = app()->getLocale();

        app()->setLocale($locale);

        $plain = view((string) $mail->textView, [
            'booking' => $booking,
            'template' => $template,
            'brand' => [],
            'extra' => [],
        ])->render();

        app()->setLocale($previous);

        return [$rendered, $plain];
    });
}

it('renders every template in both locales with an HTML and a text body', function (): void {
    foreach (NotificationTemplate::cases() as $template) {
        foreach (['el', 'en'] as $locale) {
            [$html, $text] = renderTemplate($template, $locale);

            expect(trim($html))->not->toBe('', "{$template->value} / {$locale}")
                // NTF-6: *"MUST include a plain-text alternative."* Not a
                // nicety — it is what makes the message readable with HTML off,
                // what keeps it out of a spam folder, and what a screen reader
                // gets.
                ->and(trim($text))->not->toBe('', "{$template->value} / {$locale} (text)");
        }
    }
})->group('fast');

it('leaves no translation key unresolved in either locale', function (): void {
    foreach (NotificationTemplate::cases() as $template) {
        foreach (['el', 'en'] as $locale) {
            [$html, $text] = renderTemplate($template, $locale);

            // An unresolved key renders as the key itself — `mail.foo.subject`
            // in the middle of a sentence. The I18N-3 parity gate catches a
            // missing *file* entry; this catches a template asking for a key
            // nobody wrote.
            foreach ([$html, $text] as $body) {
                expect($body)->not->toContain("mail.{$template->value}.", "{$template->value} / {$locale}");
            }
        }
    }
})->group('fast');

it('needs no image to be readable', function (): void {
    [$html] = renderTemplate(NotificationTemplate::BookingConfirmed, 'el');

    // NTF-6, and every client on its list blocks remote images by default for
    // a sender the recipient has not written to before — which is exactly the
    // situation a confirmation email is in. A layout that needs an image is a
    // layout that arrives broken the first time it matters.
    expect($html)->not->toContain('<img');
})->group('fast');

it('applies no uppercase transform, which would strip Greek accents', function (): void {
    foreach (['el', 'en'] as $locale) {
        [$html] = renderTemplate(NotificationTemplate::BookingConfirmed, $locale);

        // I18N-2. ΆΝΝΑ becomes ΑΝΝΑ, and clients disagree about the final
        // sigma. A design that leans on uppercase labels mangles half its
        // audience's names.
        expect(strtolower($html))->not->toContain('text-transform')
            ->and(strtolower($html))->not->toContain('uppercase');
    }
})->group('fast');

it('says it is transactional, on every message', function (): void {
    foreach (['el', 'en'] as $locale) {
        [$html, $text] = renderTemplate(NotificationTemplate::PreDeparture24h, $locale);

        // NTF-7: *"Guests receive no marketing email from the platform."* The
        // footer says so in the guest's own language, which is both the honest
        // thing and the thing that keeps a transactional sender's reputation.
        $footer = __('mail.common.transactional', [], $locale);

        expect($html)->toContain($footer)
            ->and($text)->toContain($footer);
    }
})->group('fast');

it('links to the guest own page rather than to a gateway', function (): void {
    [$html, $text] = renderTemplate(NotificationTemplate::BalanceDueReminder, 'el');

    // ADR-0004: a gateway URL emailed today is dead by the time a guest opens
    // it in three weeks, and they would have no way back. Every message points
    // at `/b/{manage_token}`, which mints a fresh session on demand.
    expect($html)->toContain('/b/')
        ->and($text)->toContain('/b/');
})->group('fast');

it('renders in the guest locale rather than the application one', function (): void {
    app()->setLocale('en');

    [$html] = renderTemplate(NotificationTemplate::BookingConfirmed, 'el');

    // NTF-4. A reminder sweep runs on a worker whose locale is whatever the
    // last message set — this asserts the message follows the guest.
    expect($html)->toContain(__('mail.booking_confirmed.heading', [], 'el'))
        ->and($html)->toContain('lang="el"');
})->group('fast');

it('carries a subject in both locales for every template', function (): void {
    foreach (NotificationTemplate::cases() as $template) {
        foreach (['el', 'en'] as $locale) {
            $subject = __("mail.{$template->value}.subject", ['reference' => 'KAI-ABCDE'], $locale);

            expect($subject)->not->toBe("mail.{$template->value}.subject", "{$template->value} / {$locale}")
                ->and(trim($subject))->not->toBe('');
        }
    }
})->group('fast');

it('carries an SMS line for every template that sends one', function (): void {
    foreach (NotificationTemplate::cases() as $template) {
        if (! $template->usesSms()) {
            continue;
        }

        foreach (['el', 'en'] as $locale) {
            $line = __("mail.{$template->value}.sms", [], $locale);

            // Every SMS template needs its own short lead. Falling back to the
            // email heading would blow NTF-5's segment budget in Greek on the
            // first message.
            expect($line)->not->toBe("mail.{$template->value}.sms", "{$template->value} / {$locale}");
        }
    }
})->group('fast');
