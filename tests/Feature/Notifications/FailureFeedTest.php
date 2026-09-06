<?php

declare(strict_types=1);

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\NotificationTemplate;
use App\Enums\Role;
use App\Filament\App\Resources\NotificationLogResource;
use App\Models\NotificationLog;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| BKG-14, CNV-11: a plain-Greek explanation and a retry button
|--------------------------------------------------------------------------
|
| > *Failed listeners appear in the operator panel with a plain-Greek
| > explanation and a retry button.*
|
| Both halves, asserted. The explanation must never be the provider's own words:
| "SMTP 535: bad credentials" is written for a developer by a company the
| operator has never heard of, which is the same rule PAY-12 sets for gateways.
| The retry must actually re-send, and must rebuild the message from the booking
| as it stands now — because the reason it failed is often that something needed
| correcting.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    Carbon::setTestNow('2026-07-03 11:00:00');
    Mail::fake();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('lets an owner and a manager reach the message feed', function (Role $role): void {
    actingAs(OperatorUser::withRole($role))->get('/app/notification-logs')->assertSuccessful();
})->with([[Role::Owner], [Role::Manager]])->group('fast');

it('refuses crew the message feed', function (): void {
    // A log row carries a guest's email address and phone number in `to`. Crew
    // know who is aboard today, and a stranger's contact details are not part
    // of that — the same reasoning `EnquiryPolicy` gives.
    actingAs(OperatorUser::withRole(Role::Crew))->get('/app/notification-logs')->assertForbidden();
})->group('fast');

it('explains a provider code as a sentence an operator can act on', function (): void {
    // CNV-11 and PAY-12's rule: never the provider's own English.
    expect(NotificationLogResource::explain('apifon_not_configured'))
        ->toBe(__('notifications.errors.apifon_not_configured'))
        ->and(NotificationLogResource::explain('apifon_not_configured'))
        ->toContain('Integrations');
})->group('fast');

it('falls back to a generic sentence rather than showing a raw code', function (): void {
    // A code nobody wrote a line for — a new provider error, or a class name
    // from an exception. The operator gets a sentence, not `SMTP 535`.
    expect(NotificationLogResource::explain('some_code_nobody_mapped'))
        ->toBe(__('notifications.errors.unknown'))
        ->and(NotificationLogResource::explain(null))->toBe('—');
})->group('fast');

it('explains the dropped overnight reminder in the operator own words', function (): void {
    // BKG-18's *"dropped and logged"*. The row exists precisely so an operator
    // asking "why did my guest not get the text" has something to read, and
    // this is the sentence they read.
    expect(NotificationLogResource::explain('quiet_hours_would_deliver_after_the_event'))
        ->toContain('08:00');
})->group('fast');

it('offers the retry button only on rows that need one', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant(Tenant::query()->findOrFail($owner->tenant_id), function (): void {
        $sent = NotificationLog::factory()->create();
        $failed = NotificationLog::factory()->failed()->create();

        expect(NotificationLogResource::retryAction()->record($sent)->isVisible())->toBeFalse()
            ->and(NotificationLogResource::retryAction()->record($failed)->isVisible())->toBeTrue();
    });
})->group('fast');

it('counts only the rows that need a person on the badge', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant(Tenant::query()->findOrFail($owner->tenant_id), function (): void {
        NotificationLog::factory()->count(3)->create();
        NotificationLog::factory()->failed()->create();
        NotificationLog::factory()->bounced()->create();

        // A badge counting every message ever sent always shows a large number
        // and therefore says nothing.
        expect(NotificationLogResource::getNavigationBadge())->toBe('2');
    });
})->group('fast');

it('sends again when the button is pressed, and keeps the failure on the record', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant(Tenant::query()->findOrFail($owner->tenant_id), function (): void {
        $failed = NotificationLog::factory()->failed()->create([
            'template' => NotificationTemplate::PreDeparture24h,
            'channel' => NotificationChannel::Mail,
        ]);

        NotificationLogResource::retryAction()->record($failed)->call();

        // A **new row**, not a flipped one. The log records attempts, and
        // erasing the first failure would erase exactly the history an operator
        // asking "why did the guest never hear from us" needs.
        expect($failed->refresh()->status)->toBe(NotificationStatus::Failed)
            ->and(NotificationLog::query()
                ->where('booking_id', $failed->booking_id)
                ->where('template', NotificationTemplate::PreDeparture24h->value)
                ->count())->toBe(2);
    });
})->group('fast');

it('shows an operator only their own messages', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    $otherTenant = Tenant::factory()->create();

    $theirs = Tenancy::forTenant($otherTenant, fn (): NotificationLog => NotificationLog::factory()->failed()->create());

    Tenancy::forTenant(Tenant::query()->findOrFail($owner->tenant_id), function () use ($theirs): void {
        expect(NotificationLogResource::getEloquentQuery()->pluck('id')->all())
            ->not->toContain($theirs->getKey());
    });
})->group('fast');
