<?php

declare(strict_types=1);

use App\Enums\NotificationTemplate;
use App\Enums\Role;
use App\Mail\GuestMail;
use App\Mail\StaffInvitationMail;
use App\Mail\Support\OperatorSender;
use App\Models\Tenant;
use App\Support\Tenancy;
use Tests\Support\Booking\GuestPageScenario;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| From the platform, in the operator's name, answered to the operator
|--------------------------------------------------------------------------
|
| Decided 2026-09-23, over each operator's own SMTP: a confirmation must never
| quietly stop because somebody changed a mailbox password. The guest still
| sees the operator — their name in the inbox, their address when they press
| reply — and the From *address* stays the one the platform's provider has
| verified, because a From domain the provider cannot sign for is refused or
| quarantined.
|
*/

beforeEach(function (): void {
    config([
        'mail.from.address' => 'bookings@kaiki.example',
        'mail.from.name' => 'Kaiki',
    ]);
});

it('sends a guest from the platform address under the operator\'s name', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking();

    Tenancy::withoutTenancy(static fn () => Tenant::query()->whereKey($tenant->getKey())->update([
        'name' => 'Aegean Blue',
        'email' => 'hello@aegean-blue.example',
    ]));

    // The assertions render the message, and the body reads tenant-owned rows.
    Tenancy::forTenant($tenant->refresh(), static function () use ($booking): void {
        $mail = new GuestMail($booking->refresh(), NotificationTemplate::BookingConfirmed);

        $mail->assertFrom('bookings@kaiki.example', 'Aegean Blue');
        $mail->assertHasReplyTo('hello@aegean-blue.example', 'Aegean Blue');
    });
})->group('fast');

it('leaves reply-to off rather than pointing it at a mailbox nobody reads', function (): void {
    $tenant = Tenant::factory()->make(['name' => 'No Inbox', 'email' => 'not an address']);

    expect(OperatorSender::replyTo($tenant))->toBe([])
        ->and(OperatorSender::replyTo(null))->toBe([])
        // And the name still carries, over the platform's address.
        ->and(OperatorSender::from($tenant)->address)->toBe('bookings@kaiki.example')
        ->and(OperatorSender::from($tenant)->name)->toBe('No Inbox');
})->group('fast');

it('falls back to the platform name when there is no operator', function (): void {
    expect(OperatorSender::from(null)->name)->toBe('Kaiki');
})->group('fast');

it('keeps a line break in an operator name out of the headers', function (): void {
    // The name is typed by an operator. A newline in it would be a header of
    // their choosing in every message the platform sends on their behalf.
    $tenant = Tenant::factory()->make(['name' => "Aegean\r\nBcc: someone@example.com", 'email' => 'a@example.com']);

    expect(OperatorSender::from($tenant)->name)->toBe('Aegean Bcc: someone@example.com')
        ->and(OperatorSender::replyTo($tenant)[0]->name)->not->toContain("\n");
})->group('fast');

it('sends a staff invitation in the operator\'s name, answered to whoever sent it', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $owner->forceFill(['name' => 'Μαρία Παπαδοπούλου', 'email' => 'maria@aegean-blue.example'])->save();
    $invitee = OperatorUser::withRole(Role::Crew, $owner->tenant);

    Tenancy::withoutTenancy(static fn () => Tenant::query()->whereKey($owner->tenant_id)->update(['name' => 'Aegean Blue']));

    $mail = Tenancy::forTenant($owner->tenant->refresh(), static fn (): StaffInvitationMail => tap(
        new StaffInvitationMail($invitee, 'https://example.test/reset', $owner),
        static function (StaffInvitationMail $mail): void {
            $mail->assertFrom('bookings@kaiki.example', 'Aegean Blue');
            $mail->assertHasReplyTo('maria@aegean-blue.example', 'Μαρία Παπαδοπούλου');
        },
    ));

    expect($mail)->toBeInstanceOf(StaffInvitationMail::class);
})->group('fast');
