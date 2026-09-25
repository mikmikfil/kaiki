<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\CollectBalanceOnBoard;
use App\Enums\AuditAction;
use App\Enums\BookingStatus;
use App\Enums\PaymentGatewayName;
use App\Enums\PaymentKind;
use App\Enums\Role;
use App\Filament\App\Pages\CheckIn;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\Support\Booking\CheckInScenario;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| «Οφείλει €X» → «Πληρώθηκε» at boarding (Mike, 2026-09-25; plan Β2)
|--------------------------------------------------------------------------
|
| Crew may record that a booking's whole open balance was paid on the boat, in
| cash or on the POS — and nothing wider: no other amount, no bank transfer, no
| booking outside the boarding window, nothing cancelled. The refusals are the
| half worth writing down, because a Livewire argument can name any booking.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    Carbon::setTestNow('2026-07-03 08:45:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @return array{0: Tenant, 1: Booking, 2: User} */
function owingOnBoard(string $startsAt = '2026-07-03 09:00:00', BookingStatus $status = BookingStatus::Confirmed): array
{
    [$tenant, $booking] = CheckInScenario::sailing(Carbon::parse($startsAt), status: $status);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $booking->forceFill([
            'total_cents' => 12000,
            'deposit_cents' => 3600,
            'paid_cents' => 3600,
            'balance_cents' => 8400,
        ])->save();

        // The deposit paid online at checkout: `paid_cents` is derived from
        // the payment rows (PAY-10), so the row has to exist.
        Payment::factory()->deposit(3600)->create(['booking_id' => $booking->getKey()]);
    });

    $crew = OperatorUser::withRole(Role::Crew, $tenant);

    // The panel speaks the user's own language; these assertions read Greek.
    $crew->forceFill(['locale' => 'el'])->save();

    return [$tenant, $booking->refresh(), $crew];
}

it('records the whole open balance for crew, and who did it', function (): void {
    [$tenant, $booking, $crew] = owingOnBoard();

    actingAs($crew);

    Tenancy::forTenant($tenant, function () use ($booking, $crew): void {
        app(CollectBalanceOnBoard::class)($booking, PaymentGatewayName::Cash, $crew);

        $payment = Payment::query()->where('booking_id', $booking->getKey())->where('gateway', PaymentGatewayName::Cash->value)->sole();
        $entry = AuditLog::query()->where('action', AuditAction::PaymentRecorded->value)->sole();

        expect($booking->refresh()->balance_cents)->toBe(0)
            ->and($booking->paid_cents)->toBe(12000)
            ->and($payment->amount_cents)->toBe(8400)
            ->and($payment->gateway)->toBe(PaymentGatewayName::Cash)
            ->and($payment->kind)->toBe(PaymentKind::Balance)
            ->and($entry->user_id)->toBe($crew->getKey())
            ->and($entry->context['amount_cents'] ?? null)->toBe(8400);
    });
});

it('refuses crew a bank transfer', function (): void {
    [$tenant, $booking, $crew] = owingOnBoard();

    Tenancy::forTenant($tenant, fn () => app(CollectBalanceOnBoard::class)($booking, PaymentGatewayName::BankTransfer, $crew));
})->throws(ValidationException::class);

it('refuses a booking outside the boarding window', function (): void {
    // Tomorrow evening: a crew member can open it in the bookings list, and
    // must still not settle it from the quay today.
    [$tenant, $booking, $crew] = owingOnBoard('2026-07-04 17:00:00');

    Tenancy::forTenant($tenant, fn () => app(CollectBalanceOnBoard::class)($booking, PaymentGatewayName::Cash, $crew));
})->throws(ValidationException::class);

it('refuses a cancelled booking', function (): void {
    [$tenant, $booking, $crew] = owingOnBoard(status: BookingStatus::Cancelled);

    Tenancy::forTenant($tenant, fn () => app(CollectBalanceOnBoard::class)($booking, PaymentGatewayName::Cash, $crew));
})->throws(ValidationException::class);

it('refuses a booking that owes nothing', function (): void {
    [$tenant, $booking, $crew] = owingOnBoard();

    Tenancy::forTenant($tenant, function () use ($booking, $crew): void {
        $booking->forceFill(['paid_cents' => 12000, 'balance_cents' => 0])->save();

        app(CollectBalanceOnBoard::class)($booking, PaymentGatewayName::Cash, $crew);
    });
})->throws(ValidationException::class);

it('refuses somebody with no role at all', function (): void {
    [$tenant, $booking] = owingOnBoard();

    $nobody = User::factory()->forTenant($tenant)->create();

    Tenancy::forTenant($tenant, fn () => app(CollectBalanceOnBoard::class)($booking, PaymentGatewayName::Cash, $nobody));
})->throws(AuthorizationException::class);

it('shows «Οφείλει» on the boarding list and records it from the button', function (): void {
    [$tenant, $booking, $crew] = owingOnBoard();

    app()->setLocale('el');

    actingAs($crew)->get('/app/check-in')
        ->assertSuccessful()
        ->assertSee('Οφείλει 84,00', escape: false);

    tenancy()->initialize($tenant);

    $page = Livewire::actingAs($crew)->test(CheckIn::class);

    $page->callAction(
        $page->instance()->collectBalanceAction()->getName(),
        ['gateway' => PaymentGatewayName::Pos->value],
        ['booking' => $booking->getKey()],
    )->assertHasNoActionErrors();

    expect($booking->refresh()->balance_cents)->toBe(0)
        ->and(Payment::query()->where('booking_id', $booking->getKey())->latest('id')->value('gateway'))->toBe(PaymentGatewayName::Pos);
});

it('asks how it was paid before recording anything', function (): void {
    [$tenant, $booking, $crew] = owingOnBoard();

    tenancy()->initialize($tenant);

    Livewire::actingAs($crew)->test(CheckIn::class)
        ->callAction('collectBalance', [], ['booking' => $booking->getKey()])
        ->assertHasActionErrors(['gateway' => 'required']);

    expect(Payment::query()->where('booking_id', $booking->getKey())->count())->toBe(1);
});

it('shows no figure on a booking that owes nothing', function (): void {
    [$tenant, $booking, $crew] = owingOnBoard();

    Tenancy::forTenant($tenant, fn () => $booking->forceFill(['paid_cents' => 12000, 'balance_cents' => 0])->save());

    app()->setLocale('el');

    actingAs($crew)->get('/app/check-in')
        ->assertSuccessful()
        ->assertSee($booking->reference)
        ->assertDontSee('Οφείλει')
        ->assertDontSee('€');
});

it('puts «Οφείλει» on one passenger in the offline manifest', function (): void {
    [, $booking, $crew] = owingOnBoard();

    app()->setLocale('el');

    $body = (string) actingAs($crew)->get(route('filament.app.boarding'))->assertOk()->getContent();

    // Once per booking, on the lead passenger: the balance is the booking's,
    // and two rows saying it would read as two debts.
    expect(substr_count($body, 'Οφείλει 84,00'))->toBe(1)
        ->and($body)->toContain($booking->reference);
});
