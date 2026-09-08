<?php

declare(strict_types=1);

use App\Domain\Pricing\Actions\IssueVoucher;
use App\Domain\Pricing\Actions\SendVoucherExpiryReminders;
use App\Enums\NotificationChannel;
use App\Enums\NotificationTemplate;
use App\Enums\VoucherReason;
use App\Enums\VoucherStatus;
use App\Jobs\ExpireVouchersJob;
use App\Models\Booking;
use App\Models\NotificationLog;
use App\Models\Tenant;
use App\Models\Voucher;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/*
|--------------------------------------------------------------------------
| #126 — the clock around a voucher (OPS-16, PRC-21)
|--------------------------------------------------------------------------
|
| The arithmetic has been right since M2 and is tested elsewhere. What was
| missing was everything time-shaped: a voucher stayed `active` in the panel
| for ever after it expired, and the two expiry reminders had templates, a
| column and no code.
|
| Both failures are the same kind and neither breaks a booking. A guest is
| never allowed to spend an expired voucher — `isSpendable()` has always
| refused — so the cost is an operator reading `active` off a screen and
| telling somebody on the telephone that their credit is still good.
|
| The timezone case is the one worth the file. PRC-21 evaluates expiry at end
| of day **in the tenant's zone**, and a naive `where('expires_at', '<', now())`
| expires an Aegean operator's vouchers three hours before their own day ends.
|
*/

/** @param  array<string, mixed>  $attributes */
function voucherFor(Tenant $tenant, array $attributes = []): Voucher
{
    return Tenancy::forTenant($tenant, fn (): Voucher => Voucher::factory()->create($attributes));
}

it('marks a voucher expired once the tenant\'s day has ended', function (): void {
    Carbon::setTestNow('2026-09-08 09:00:00');

    $tenant = Tenant::factory()->create(['timezone' => 'Europe/Athens']);

    $stale = voucherFor($tenant, [
        'status' => VoucherStatus::Active,
        'expires_at' => Carbon::parse('2026-09-06 23:59:59', 'Europe/Athens')->utc(),
    ]);

    (new ExpireVouchersJob)->handle();

    expect($stale->refresh()->status)->toBe(VoucherStatus::Expired);
});

it('leaves a voucher that expires today alone until tomorrow', function (): void {
    // 00:30 in Athens on the 8th is still the 7th in UTC. A cross-tenant
    // `expires_at < now()` would expire this one, and the guest holding it has
    // a date on their email that has not arrived.
    Carbon::setTestNow(Carbon::parse('2026-09-08 00:30:00', 'Europe/Athens')->utc());

    $tenant = Tenant::factory()->create(['timezone' => 'Europe/Athens']);

    $today = voucherFor($tenant, [
        'status' => VoucherStatus::Active,
        'expires_at' => Carbon::parse('2026-09-08 23:59:59', 'Europe/Athens')->utc(),
    ]);

    (new ExpireVouchersJob)->handle();

    expect($today->refresh()->status)->toBe(VoucherStatus::Active);
});

it('never rewrites a voucher that was spent or withdrawn', function (): void {
    Carbon::setTestNow('2026-09-08 09:00:00');

    $tenant = Tenant::factory()->create(['timezone' => 'Europe/Athens']);

    $past = Carbon::parse('2026-09-01 12:00:00')->utc();

    $redeemed = voucherFor($tenant, ['status' => VoucherStatus::Redeemed, 'expires_at' => $past]);
    $cancelled = voucherFor($tenant, ['status' => VoucherStatus::Cancelled, 'expires_at' => $past]);

    (new ExpireVouchersJob)->handle();

    // Expiry is a thing that befalls a voucher nobody used. Overwriting either
    // of these would erase what actually happened to it.
    expect($redeemed->refresh()->status)->toBe(VoucherStatus::Redeemed)
        ->and($cancelled->refresh()->status)->toBe(VoucherStatus::Cancelled);
});

it('leaves a voucher with no expiry alone for ever', function (): void {
    Carbon::setTestNow('2026-09-08 09:00:00');

    $tenant = Tenant::factory()->create(['timezone' => 'Europe/Athens']);

    $forever = voucherFor($tenant, ['status' => VoucherStatus::Active, 'expires_at' => null]);

    (new ExpireVouchersJob)->handle();

    expect($forever->refresh()->status)->toBe(VoucherStatus::Active);
});

it('expires each tenant against its own clock', function (): void {
    // 22:30 UTC. It is already the 9th in Athens and still the 8th in London.
    Carbon::setTestNow(Carbon::parse('2026-09-08 22:30:00', 'UTC'));

    $athens = Tenant::factory()->create(['timezone' => 'Europe/Athens']);
    $london = Tenant::factory()->create(['timezone' => 'Europe/London']);

    $expiry = Carbon::parse('2026-09-08 12:00:00', 'UTC');

    $greek = voucherFor($athens, ['status' => VoucherStatus::Active, 'expires_at' => $expiry]);
    $english = voucherFor($london, ['status' => VoucherStatus::Active, 'expires_at' => $expiry]);

    (new ExpireVouchersJob)->handle();

    expect($greek->refresh()->status)->toBe(VoucherStatus::Expired)
        ->and($english->refresh()->status)->toBe(VoucherStatus::Active);
});

/*
|--------------------------------------------------------------------------
| Issuing one by hand
|--------------------------------------------------------------------------
*/

it('issues a goodwill voucher against no booking at all', function (): void {
    $tenant = Tenant::factory()->create();

    $voucher = Tenancy::forTenant($tenant, fn (): ?Voucher => app(IssueVoucher::class)->goodwill(
        cents: 2500,
        reason: VoucherReason::Goodwill,
        note: 'Καθυστέρησε το σκάφος.',
    ));

    expect($voucher)->not->toBeNull()
        ->and($voucher->amount_cents)->toBe(2500)
        ->and($voucher->remaining_cents)->toBe(2500)
        ->and($voucher->status)->toBe(VoucherStatus::Active)
        // The column is nullable precisely for this. A voucher handed over the
        // counter has no booking behind it.
        ->and($voucher->issued_for_booking_id)->toBeNull()
        ->and($voucher->code)->toStartWith('GIFT-');
});

it('refuses to issue nothing', function (): void {
    $tenant = Tenant::factory()->create();

    expect(Tenancy::forTenant($tenant, fn () => app(IssueVoucher::class)->goodwill(cents: 0)))->toBeNull();
});

it('lets a goodwill voucher have no expiry at all', function (): void {
    $tenant = Tenant::factory()->create();

    $voucher = Tenancy::forTenant($tenant, fn (): ?Voucher => app(IssueVoucher::class)->goodwill(cents: 1000));

    // "Whenever you like" is a real answer, and `hasExpired()` is false for a
    // null — so this one never ages out.
    expect($voucher->expires_at)->toBeNull()
        ->and($voucher->hasExpired())->toBeFalse()
        ->and($voucher->isSpendable())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The two reminders
|--------------------------------------------------------------------------
*/

/** A voucher attached to a real booking, which is the only kind that can be emailed. */
function remindableVoucher(Tenant $tenant, Carbon $expires): Voucher
{
    return Tenancy::forTenant($tenant, function () use ($expires): Voucher {
        $booking = Booking::factory()->create(['is_test' => false]);

        return Voucher::factory()->create([
            'status' => VoucherStatus::Active,
            'remaining_cents' => 5000,
            'expires_at' => $expires,
            'issued_for_booking_id' => $booking->getKey(),
            'expiry_reminder_sent_at' => null,
        ]);
    });
}

it('warns a month out, then again a week out', function (): void {
    Mail::fake();
    Carbon::setTestNow('2026-09-08 09:00:00');

    $tenant = Tenant::factory()->create();
    $voucher = remindableVoucher($tenant, Carbon::parse('2026-10-06 23:59:59'));

    expect(app(SendVoucherExpiryReminders::class)())->toBe(1);

    Tenancy::forTenant($tenant, function (): void {
        expect(NotificationLog::query()
            ->where('template', NotificationTemplate::VoucherExpiry30d)
            ->where('channel', NotificationChannel::Mail)
            ->count())->toBe(1);
    });

    // Same day again: nothing, because the thirty-day window has been served.
    expect(app(SendVoucherExpiryReminders::class)())->toBe(0);

    // Three weeks on, inside seven days. The second reminder is due because the
    // last one was sent *before* this window opened — which is the whole job of
    // the single `expiry_reminder_sent_at` column.
    Carbon::setTestNow('2026-10-01 09:00:00');

    expect(app(SendVoucherExpiryReminders::class)())->toBe(1);

    Tenancy::forTenant($tenant, function (): void {
        expect(NotificationLog::query()
            ->where('template', NotificationTemplate::VoucherExpiry7d)
            ->count())->toBe(1);
    });

    expect($voucher->refresh()->expiry_reminder_sent_at)->not->toBeNull();
});

it('sends only the seven-day warning for a voucher issued inside the month', function (): void {
    Mail::fake();
    Carbon::setTestNow('2026-09-08 09:00:00');

    $tenant = Tenant::factory()->create();
    remindableVoucher($tenant, Carbon::parse('2026-09-13 23:59:59'));

    expect(app(SendVoucherExpiryReminders::class)())->toBe(1);

    Tenancy::forTenant($tenant, function (): void {
        // A warning about a month that has already passed is noise, so the
        // narrower window wins.
        expect(NotificationLog::query()->where('template', NotificationTemplate::VoucherExpiry7d)->count())->toBe(1)
            ->and(NotificationLog::query()->where('template', NotificationTemplate::VoucherExpiry30d)->count())->toBe(0);
    });
});

it('says nothing about a voucher with nothing left on it', function (): void {
    Mail::fake();
    Carbon::setTestNow('2026-09-08 09:00:00');

    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        $booking = Booking::factory()->create();

        Voucher::factory()->create([
            'status' => VoucherStatus::Active,
            'remaining_cents' => 0,
            'expires_at' => Carbon::parse('2026-10-06'),
            'issued_for_booking_id' => $booking->getKey(),
        ]);
    });

    // Telling somebody their spent credit is about to expire makes them check,
    // find nothing, and trust the next message less.
    expect(app(SendVoucherExpiryReminders::class)())->toBe(0);
});

it('cannot warn about a voucher that was handed over in person', function (): void {
    Mail::fake();
    Carbon::setTestNow('2026-09-08 09:00:00');

    $tenant = Tenant::factory()->create();

    voucherFor($tenant, [
        'status' => VoucherStatus::Active,
        'remaining_cents' => 5000,
        'expires_at' => Carbon::parse('2026-10-06'),
        // No booking, therefore no email address anywhere. This is a silence
        // with a reason, not a gap: inventing a contact field so the platform
        // could email a stranger would be collecting personal data for a
        // message nobody asked for.
        'issued_for_booking_id' => null,
    ]);

    expect(app(SendVoucherExpiryReminders::class)())->toBe(0);
});

it('says nothing about a test booking\'s voucher', function (): void {
    Mail::fake();
    Carbon::setTestNow('2026-09-08 09:00:00');

    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        $booking = Booking::factory()->create(['is_test' => true]);

        Voucher::factory()->create([
            'status' => VoucherStatus::Active,
            'remaining_cents' => 5000,
            'expires_at' => Carbon::parse('2026-10-06'),
            'issued_for_booking_id' => $booking->getKey(),
        ]);
    });

    // SAA-12. An operator testing the flow should not email themselves as a
    // guest, and a real guest must never receive a message about a fake credit.
    expect(app(SendVoucherExpiryReminders::class)())->toBe(0);
});

it('reminds about each of a guest\'s vouchers, not just the first', function (): void {
    Mail::fake();
    Carbon::setTestNow('2026-09-08 09:00:00');

    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        $booking = Booking::factory()->create();

        foreach ([1, 2] as $_) {
            Voucher::factory()->create([
                'status' => VoucherStatus::Active,
                'remaining_cents' => 5000,
                'expires_at' => Carbon::parse('2026-10-06'),
                'issued_for_booking_id' => $booking->getKey(),
            ]);
        }
    });

    // A guest whose trip was cancelled twice has two vouchers. The dedupe that
    // matters is per **voucher**, and `NotificationLog::alreadySent()` dedupes
    // per booking — so the second would never be mentioned if this went through
    // the ordinary `once: true` path.
    expect(app(SendVoucherExpiryReminders::class)())->toBe(2);
});

it('shows one operator nothing of another\'s vouchers', function (): void {
    $mine = Tenant::factory()->create();
    $theirs = Tenant::factory()->create();

    voucherFor($mine, ['code' => 'GIFT-MINE1234']);
    voucherFor($theirs, ['code' => 'GIFT-THEIRS12']);

    expect(Tenancy::forTenant($mine, fn (): int => Voucher::query()->count()))->toBe(1)
        ->and(Tenancy::forTenant($mine, fn () => Voucher::query()->first()->code))->toBe('GIFT-MINE1234');
});
