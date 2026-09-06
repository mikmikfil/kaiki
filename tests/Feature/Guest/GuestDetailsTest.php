<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\SaveGuestDetails;
use App\Enums\BookingMode;
use App\Enums\GuestDetailsStatus;
use App\Models\BookingGuest;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

use Tests\Support\Booking\GuestPageScenario;

/*
|--------------------------------------------------------------------------
| TOK-8, TOK-9, TOK-10, SEC-14: the manifest a guest fills in
|--------------------------------------------------------------------------
|
| The requirement most likely to be discovered broken in M6 rather than here is
| TOK-10's: the page stays reachable after departure, and keeps rendering after
| the document purge. A 404 would be a guest tapping a link in their own inbox
| and being told it is not valid — indistinguishable, to them, from the security
| failure TOK-4 is about. A 500 would be worse, and it would arrive a year after
| anybody was looking.
|
| So the post-purge case is a **rendering branch**, and it has a test here
| rather than a bug report in M6.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-06-20 09:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('renders one row per passenger and offers no way to add another', function (): void {
    [, $booking] = GuestPageScenario::booking(documentsRequired: true, pax: 3);

    $response = get('/g/' . $booking->guest_details_token)->assertOk();

    // TOK-8: *"the number of guest rows fixed by the pax breakdown."* A form
    // that let a guest add a row would let them add a passenger to a boat with
    // a capacity, after the seats were counted and the money taken.
    expect(substr_count($response->getContent(), 'name="guests['))->toBeGreaterThan(0)
        ->and(substr_count($response->getContent(), '][position]"'))->toBe(3);
});

it('explains why the document is wanted, how long it is kept and who sees it', function (): void {
    [, $booking] = GuestPageScenario::booking(documentsRequired: true);

    $response = get('/g/' . $booking->guest_details_token)->assertOk();

    // TOK-9's three questions. Not decoration: a guest asked for a passport
    // number with no explanation closes the tab, and the operator cannot sail.
    expect($response->getContent())
        ->toContain(__('guest.details.why.manifest'))
        ->toContain(__('guest.details.why.retention'))
        ->toContain(__('guest.details.why.who'));
});

it('saves a partial form and stays pending', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(documentsRequired: true, pax: 2);

    post('/g/' . $booking->guest_details_token, [
        'guests' => [
            ['position' => 1, 'full_name' => 'Γιώργος Νικολάου'],
        ],
    ])->assertRedirect();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        // TOK-8: partial saves are the normal case. A family fills in three
        // passports on the sofa and the fourth when somebody comes home.
        expect(BookingGuest::query()->where('position', 1)->sole()->full_name)
            ->toBe('Γιώργος Νικολάου')
            ->and($booking->refresh()->guest_details_status)->toBe(GuestDetailsStatus::Pending);
    });
});

it('turns complete only when every required field is there for every guest', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(documentsRequired: true, pax: 2);

    $full = static fn (int $position, string $name): array => [
        'position' => $position,
        'full_name' => $name,
        'date_of_birth' => '1988-04-12',
        'nationality' => 'GR',
        'document_type' => 'passport',
        'document_number' => 'AB1234567',
    ];

    // One of two. Still pending — completeness is a question asked of *all* the
    // stored rows, not of the form that was submitted.
    post('/g/' . $booking->guest_details_token, ['guests' => [$full(1, 'Γιώργος Νικολάου')]])->assertRedirect();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect($booking->refresh()->guest_details_status)->toBe(GuestDetailsStatus::Pending);
    });

    post('/g/' . $booking->guest_details_token, ['guests' => [$full(2, 'Ελένη Νικολάου')]])->assertRedirect();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect($booking->refresh()->guest_details_status)->toBe(GuestDetailsStatus::Complete);
    });
});

it('does not need a document when the operator did not ask for one', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(documentsRequired: false, pax: 1);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $booking->forceFill(['guest_details_status' => GuestDetailsStatus::Pending])->save();
    });

    post('/g/' . $booking->guest_details_token, [
        'guests' => [['position' => 1, 'full_name' => 'Γιώργος Νικολάου']],
    ])->assertRedirect();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        // Most day trips do not need a passport number, and collecting one
        // anyway would be personal data taken for no stated purpose — which
        // GDR-4 and TOK-9 both object to.
        expect($booking->refresh()->guest_details_status)->toBe(GuestDetailsStatus::Complete);
    });
});

it('ignores a position that does not exist rather than losing the whole submission', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(documentsRequired: true, pax: 2);

    post('/g/' . $booking->guest_details_token, [
        'guests' => [
            ['position' => 1, 'full_name' => 'Γιώργος Νικολάου'],
            // A row for a passenger who is not on this booking.
            ['position' => 99, 'full_name' => 'Nobody At All'],
        ],
    ])->assertRedirect();

    Tenancy::forTenant($tenant, function (): void {
        // The real row was written and nothing was created. Refusing the whole
        // submission would cost a guest the rows they *did* fill in.
        expect(BookingGuest::query()->count())->toBe(2)
            ->and(BookingGuest::query()->where('position', 1)->sole()->full_name)
            ->toBe('Γιώργος Νικολάου');
    });
});

it('stays readable and stops being writable after departure', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(documentsRequired: true, pax: 1);

    Carbon::setTestNow('2026-07-05 09:00:00');

    // TOK-10: **read-only, not gone.**
    $response = get('/g/' . $booking->guest_details_token)->assertOk();

    expect($response->getContent())->toContain(__('guest.details.read_only'));

    post('/g/' . $booking->guest_details_token, [
        'guests' => [['position' => 1, 'full_name' => 'Written After Sailing']],
    ])->assertRedirect();

    Tenancy::forTenant($tenant, function (): void {
        expect(BookingGuest::query()->sole()->full_name)->toBeNull();
    });
});

it('still renders after the document purge, without the fields', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(documentsRequired: true, pax: 1);

    Tenancy::forTenant($tenant, function (): void {
        BookingGuest::query()->sole()->forceFill([
            'full_name' => 'Γιώργος Νικολάου',
            'document_number' => null,
            'document_purged_at' => now(),
        ])->save();
    });

    $response = get('/g/' . $booking->guest_details_token)->assertOk();

    // The branch the issue's note asked for. A missing document number renders
    // a sentence, not a 500 — and the failure it guards against arrives in M6,
    // a year after anybody is looking at this file.
    // `str_contains` for the negative half: chaining `->not` after `->toContain`
    // on a `string|false` expectation is not something PHPStan can follow, and
    // an annotation asserting otherwise would be asserting something about
    // Pest rather than about this page.
    expect($response->getContent())->toContain(__('guest.details.purged'))
        ->and(str_contains((string) $response->getContent(), 'name="guests[0][document_number]"'))
        ->toBeFalse();
});

it('counts a purged row as complete rather than flipping the booking back to pending', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(documentsRequired: true, pax: 1);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        BookingGuest::query()->sole()->forceFill([
            'full_name' => 'Γιώργος Νικολάου',
            'document_number' => null,
            'document_purged_at' => now(),
        ])->save();

        app(SaveGuestDetails::class)->syncStatus($booking);

        // After the retention window the document is *supposed* to be gone. A
        // booking that went back to `pending` a year later would put a
        // departure that already sailed into the reminder scheduler.
        expect($booking->refresh()->guest_details_status)->toBe(GuestDetailsStatus::Complete);
    });
});

it('takes the charter agreement with its timestamp and its address', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(
        documentsRequired: true,
        mode: BookingMode::PerVessel,
        pax: 1,
    );

    $response = get('/g/' . $booking->guest_details_token)->assertOk();

    expect($response->getContent())->toContain(__('guest.details.charter_agreement.accept'));

    post('/g/' . $booking->guest_details_token, [
        'guests' => [['position' => 1, 'full_name' => 'Γιώργος Νικολάου']],
        'charter_agreement' => '1',
    ])->assertRedirect();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        // TOK-8's *"timestamp and IP"*, in the columns §2.5 already names for
        // it — `terms_accepted_at` with `ip_address` beside it. No migration,
        // and M6 renders its document against evidence collected here.
        expect($booking->refresh()->terms_accepted_at)->not->toBeNull()
            ->and($booking->ip_address)->not->toBeNull();
    });
});

it('does not ask a per-seat booking to sign a charter agreement', function (): void {
    [, $booking] = GuestPageScenario::booking(documentsRequired: true, pax: 1);

    $response = get('/g/' . $booking->guest_details_token)->assertOk();

    // A ναυλοσύμφωνο is for a private charter. Asking a family on a day trip to
    // sign one would be a legal document nobody needs and a step that loses
    // them.
    expect($response->getContent())->not->toContain(__('guest.details.charter_agreement.accept'));
});
