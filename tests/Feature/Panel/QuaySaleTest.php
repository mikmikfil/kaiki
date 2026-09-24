<?php

declare(strict_types=1);

use App\Domain\Compliance\Actions\IssueInvoice;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\NotificationChannel;
use App\Enums\PaymentGatewayName;
use App\Enums\Role;
use App\Filament\App\Pages\Calendar;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\NotificationLog;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\Api\BookingApiScenario;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| «Πώληση τώρα» on the quay (Mike, 2026-09-24, option Β)
|--------------------------------------------------------------------------
|
| The guest pays on the operator's own POS or in cash; Kaiki records a
| confirmed, paid booking, issues no receipt (their cash register does) and
| sends no text message. Every role sells, crew included.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    Mail::fake();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @return array{0: array<string, mixed>, 1: User} a trip leaving in two hours, and somebody of `$role` */
function quayScenario(Role $role = Role::Crew, int $capacity = 12): array
{
    $fixture = BookingApiScenario::bookable(capacity: $capacity, unitPriceCents: 5000, startsAt: Carbon::now()->addHours(2)->startOfMinute());

    $fixture['tenant']->forceFill(['timezone' => 'Europe/Athens', 'invoice_auto_issue' => true])->save();

    return [$fixture, OperatorUser::withRole($role, $fixture['tenant'])];
}

function sellOnQuay(User $user, string $departureUuid, string $paidBy, array $form = []): void
{
    tenancy()->initialize(Tenant::query()->findOrFail($user->tenant_id));

    Livewire::actingAs($user)->test(Calendar::class)
        ->callAction('sell', [
            'pax_adult' => 2,
            'guest_name' => 'Μαρία Κ.',
            'guest_email' => null,
            'guest_phone' => '+306900000000',
            ...$form,
        ], ['departure' => $departureUuid, 'paid_by' => $paidBy])
        ->assertHasNoActionErrors();
}

/** @return list<Booking> */
function quayBookings(Tenant $tenant): array
{
    return Tenancy::forTenant($tenant, fn (): array => Booking::query()->where('source', BookingSource::Quay->value)->get()->all());
}

it('lets crew sell a seat paid on the POS: confirmed, paid, no receipt, no text message', function (): void {
    [$fixture, $crew] = quayScenario(Role::Crew);

    sellOnQuay($crew, (string) $fixture['departure']->uuid, PaymentGatewayName::Pos->value);

    $bookings = quayBookings($fixture['tenant']);
    expect($bookings)->toHaveCount(1);
    $booking = $bookings[0];

    Tenancy::forTenant($fixture['tenant'], function () use ($booking): void {
        $payment = Payment::query()->where('booking_id', $booking->getKey())->sole();

        expect($booking->status)->toBe(BookingStatus::Confirmed)
            ->and($booking->total_cents)->toBe(10000)
            ->and($booking->balance_cents)->toBe(0)
            ->and($booking->guest_email)->toBeNull()
            ->and($payment->gateway)->toBe(PaymentGatewayName::Pos)
            // Their cash register issues the receipt: nothing from Kaiki.
            ->and(Invoice::query()->count())->toBe(0)
            // The guest is standing there: no text message, and no email to an address nobody gave.
            ->and(NotificationLog::query()->where('booking_id', $booking->getKey())->where('channel', NotificationChannel::Sms)->count())->toBe(0)
            ->and(NotificationLog::query()->where('booking_id', $booking->getKey())->where('channel', NotificationChannel::Mail)->count())->toBe(0);

        expect(fn () => app(IssueInvoice::class)($booking))->toThrow(RuntimeException::class);
    });
})->group('fast');

it('sells for cash too, and emails the ticket when the guest gives an address', function (): void {
    [$fixture, $manager] = quayScenario(Role::Manager);

    sellOnQuay($manager, (string) $fixture['departure']->uuid, PaymentGatewayName::Cash->value, ['guest_email' => 'maria@example.gr']);

    $booking = quayBookings($fixture['tenant'])[0];

    Tenancy::forTenant($fixture['tenant'], function () use ($booking): void {
        expect(Payment::query()->where('booking_id', $booking->getKey())->sole()->gateway)->toBe(PaymentGatewayName::Cash)
            ->and(NotificationLog::query()->where('booking_id', $booking->getKey())->where('channel', NotificationChannel::Mail)->count())->toBe(1);
    });
})->group('fast');

it('refuses a party the boat has no room for, and sells nothing', function (): void {
    [$fixture, $crew] = quayScenario(Role::Crew, capacity: 1);

    tenancy()->initialize($fixture['tenant']);

    Livewire::actingAs($crew)->test(Calendar::class)
        // The field stops at the seats left, so the form refuses before any
        // booking is attempted; the hold's own refusal is the backstop.
        ->callAction('sell', ['pax_adult' => 2, 'guest_name' => 'Μαρία Κ.'], ['departure' => (string) $fixture['departure']->uuid, 'paid_by' => 'pos'])
        ->assertHasActionErrors(['pax_adult']);

    expect(quayBookings($fixture['tenant']))->toBe([]);
})->group('fast');

it('sells the departure it was opened on, on a day with two sailings', function (): void {
    [$fixture, $owner] = quayScenario(Role::Owner);

    $later = Tenancy::forTenant($fixture['tenant'], function () use ($fixture) {
        $later = $fixture['departure']->replicate(['uuid']);
        $later->forceFill([
            'uuid' => (string) Str::uuid(),
            'starts_at_utc' => $fixture['departure']->starts_at_utc->copy()->addHours(3),
            'ends_at_utc' => $fixture['departure']->ends_at_utc->copy()->addHours(3),
            'local_time' => Carbon::parse((string) $fixture['departure']->local_time)->addHours(3)->format('H:i:s'),
        ])->save();

        return $later;
    });

    sellOnQuay($owner, (string) $later->uuid, PaymentGatewayName::Pos->value);

    expect(quayBookings($fixture['tenant'])[0]->departure_id)->toBe($later->getKey());
})->group('fast');

it('lets nobody without a role reach the sale', function (): void {
    [$fixture] = quayScenario();
    $stranger = User::factory()->forTenant($fixture['tenant'])->create();

    tenancy()->initialize($fixture['tenant']);

    // No role, no calendar, and so no sale: the page itself is refused.
    Livewire::actingAs($stranger)->test(Calendar::class)->assertForbidden();
})->group('fast');
