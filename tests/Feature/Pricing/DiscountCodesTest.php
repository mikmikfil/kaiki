<?php

declare(strict_types=1);

use App\Domain\Analytics\Support\AnalyticsFigures;
use App\Domain\Analytics\Support\LocalRange;
use App\Domain\Pricing\Actions\ApplyDiscountCode;
use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Filament\App\Resources\DiscountCodeResource\Pages\ListDiscountCodes;
use App\Models\Booking;
use App\Models\DiscountCode;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Format\MoneyFormatter;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

use Tests\Support\Api\BookingApiScenario;
use Tests\Support\Booking\GuestPageScenario;
use Tests\Support\OperatorUser;
use Tests\Support\Payments\WebhookScenario;

/*
|--------------------------------------------------------------------------
| Discount codes, «κουπόνια» (product owner, 2026-09-17)
|--------------------------------------------------------------------------
|
| A code with an internal name; a percentage or a fixed amount; dates, a use
| limit, one trip, a switch. Applied at checkout and from the widget, with the
| snapshot — total, VAT split, deposit — rewritten so the invoice still adds
| up; counted as «Χρήσεις» and «Έσοδα που έφερε».
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    WebhookScenario::fakeGatewayResponses();
    Carbon::setTestNow('2026-06-20 09:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @return array{0: Tenant, 1: Booking} */
function codeDraft(): array
{
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 12000);

    Tenancy::forTenant($tenant, static function () use ($booking): void {
        $booking->forceFill([
            'status' => BookingStatus::Draft,
            'paid_cents' => 0,
            'balance_cents' => 12000,
            'hold_expires_at' => now()->addMinutes(15),
            'subtotal_cents' => 12000,
            'extras_cents' => 0,
            'discount_cents' => 0,
            'total_cents' => 12000,
            'price_snapshot' => [
                'lines' => [],
                'subtotal_cents' => 12000,
                'extras_cents' => 0,
                'discount_cents' => 0,
                'total_cents' => 12000,
                'vat' => ['rate_bp' => 1300, 'included' => true, 'net_cents' => 10619, 'vat_cents' => 1381],
                'deposit' => null,
            ],
        ])->save();
    });

    return [$tenant, $booking->refresh()];
}

it('takes a percentage or a fixed amount off, never below zero', function (): void {
    $percent = new DiscountCode(['kind' => 'percent', 'value' => 15]);
    $fixed = new DiscountCode(['kind' => 'fixed', 'value' => 5000]);

    expect($percent->amountFor(12345))->toBe(1852)
        ->and($fixed->amountFor(12000))->toBe(5000)
        ->and($fixed->amountFor(3000))->toBe(3000);
})->group('fast');

it('applies a code at checkout and keeps the snapshot adding up, then takes it off', function (): void {
    [$tenant, $booking] = codeDraft();

    $code = Tenancy::forTenant($tenant, static fn (): DiscountCode => DiscountCode::factory()->create(['code' => 'SUMMER10']));

    post('/c/' . $booking->manage_token . '/code', ['discount_code' => 'summer10'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('guest.checkout', ['token' => $booking->manage_token]));

    $booking->refresh();
    $vat = $booking->price_snapshot['vat'];

    expect($booking->discount_code_id)->toBe($code->getKey())
        ->and($booking->discount_cents)->toBe(1200)
        ->and($booking->total_cents)->toBe(10800)
        ->and($booking->price_snapshot['total_cents'])->toBe(10800)
        ->and($booking->price_snapshot['discount_code']['name'])->toBe('Newsletter Ιουνίου')
        // MYD-7: the invoice refuses a snapshot whose halves do not sum.
        ->and($vat['net_cents'] + $vat['vat_cents'])->toBe(10800);

    get('/c/' . $booking->manage_token)->assertOk()->assertSee('SUMMER10', escape: false)->assertSee('108,00', escape: false);

    post('/c/' . $booking->manage_token . '/code', ['remove' => '1'])->assertSessionHasNoErrors();

    expect($booking->refresh()->total_cents)->toBe(12000)
        ->and($booking->discount_code_id)->toBeNull();
})->group('fast');

it('refuses a code that is off, expired, for another trip or used up', function (array $attributes, string $reason): void {
    [$tenant, $booking] = codeDraft();

    Tenancy::forTenant($tenant, static function () use ($attributes, $booking): void {
        $code = DiscountCode::factory()->create(['code' => 'NOPE', ...$attributes]);

        if (($attributes['max_uses'] ?? null) === 1) {
            Booking::factory()->create(['discount_code_id' => $code->getKey(), 'status' => BookingStatus::Confirmed, 'product_id' => $booking->product_id]);
        }
    });

    post('/c/' . $booking->manage_token . '/code', ['discount_code' => 'NOPE'])
        ->assertSessionHasErrors(['discount_code' => __('discount_codes.refused.' . $reason, [], $booking->locale)]);

    expect($booking->refresh()->total_cents)->toBe(12000);
})->with([
    'switched off' => [['is_active' => false], 'inactive'],
    'expired' => [['valid_until' => '2026-06-19'], 'expired'],
    'not yet' => [['valid_from' => '2026-07-01'], 'not_yet'],
    'used up' => [['max_uses' => 1], 'used_up'],
])->group('fast');

it('refuses a code for another trip and one that does not exist', function (): void {
    [$tenant, $booking] = codeDraft();

    Tenancy::forTenant($tenant, static function (): void {
        DiscountCode::factory()->create(['code' => 'BOAT2', 'product_id' => Product::factory()->create()->getKey()]);
    });

    post('/c/' . $booking->manage_token . '/code', ['discount_code' => 'BOAT2'])->assertSessionHasErrors('discount_code');
    post('/c/' . $booking->manage_token . '/code', ['discount_code' => 'NOSUCH'])->assertSessionHasErrors('discount_code');
})->group('fast');

it('applies a code sent by the widget, and answers 422 for one that cannot be used', function (): void {
    $fixture = BookingApiScenario::bookable();

    Tenancy::forTenant($fixture['tenant'], static fn () => DiscountCode::factory()->fixed(1000)->create(['code' => 'WIDGET']));

    $send = static fn (string $code) => postJson(
        '/api/v1/bookings',
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band'], ['discount_code' => $code]),
        ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => BookingApiScenario::idempotencyKey()],
    );

    $send('NOSUCH')->assertStatus(422)->assertJsonPath('error.code', 'invalid_discount_code');

    $uuid = (string) $send('widget')->assertCreated()->json('data.uuid');

    $booking = Tenancy::withoutTenancy(static fn (): ?Booking => Booking::query()->withoutGlobalScopes()->where('uuid', $uuid)->first());

    expect($booking?->discount_cents)->toBe(1000)
        ->and($booking?->total_cents)->toBe($booking->subtotal_cents + $booking->extras_cents - 1000);
});

it('counts uses and the revenue a code brought, in the list and in the statistics', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $tenant = Tenant::query()->findOrFail($owner->tenant_id);
    tenancy()->initialize($tenant);

    $code = DiscountCode::factory()->create(['name' => 'Newsletter', 'code' => 'NEWS']);
    Booking::factory()->count(2)->create(['discount_code_id' => $code->getKey(), 'status' => BookingStatus::Confirmed, 'total_cents' => 9000, 'discount_cents' => 1000, 'is_test' => false]);
    Booking::factory()->create(['discount_code_id' => $code->getKey(), 'status' => BookingStatus::Draft, 'total_cents' => 9000]);

    Livewire::actingAs($owner)
        ->test(ListDiscountCodes::class)
        ->assertCanSeeTableRecords([$code])
        ->assertSee('Newsletter')
        ->assertSee(MoneyFormatter::format(18000), escape: false);

    $rows = AnalyticsFigures::forCurrentTenant()->byDiscountCode(LocalRange::between('2026-06-01', '2026-06-30', 'Europe/Athens'));

    expect($rows)->toBe([['name' => 'Newsletter', 'code' => 'NEWS', 'uses' => 2, 'revenue' => 18000, 'discount' => 2000]]);
})->group('fast');

/*
|--------------------------------------------------------------------------
| The last use, claimed under a lock (2026-09-18)
|--------------------------------------------------------------------------
|
| `refusal()` counts the bookings already holding a use. Two guests paying for
| the last one at the same moment both counted the same number, both passed, and
| a code capped at one was redeemed twice. The count is not wrong; it is that a
| count taken before a decision is a guess by the time the decision lands.
|
| `claim()` takes the code's row lock first, inside the transaction that moves a
| booking to `pending_payment`, so the loser counts after the winner has
| committed. Two connections against an in-memory database are not something a
| test can have, so what is asserted is the property the lock guarantees: the
| second claim, made once the first booking is pending payment, is refused.
|
*/

it('refuses the second claim on the last use of a code', function (): void {
    [$tenant, $first] = codeDraft();
    [, $second] = codeDraft();

    Tenancy::forTenant($tenant, function () use ($first, $second): void {
        $code = DiscountCode::query()->create([
            'code' => 'LASTONE',
            'name' => 'Η τελευταία χρήση',
            'kind' => 'fixed',
            'value' => 1000,
            'is_active' => true,
            'max_uses' => 1,
        ]);

        foreach ([$first, $second] as $booking) {
            $booking->forceFill(['discount_code_id' => $code->getKey()])->save();
        }

        // Nobody has spent it yet, so either of them could.
        expect(ApplyDiscountCode::claim($first->refresh()))->toBeTrue()
            ->and(ApplyDiscountCode::claim($second->refresh()))->toBeTrue();

        // The first one reaches the gateway: its booking now holds the use.
        $first->forceFill(['status' => BookingStatus::PendingPayment])->save();

        expect(ApplyDiscountCode::claim($second->refresh()))->toBeFalse()
            // And the winner keeps it: a claim is not a token the loser burns.
            ->and(ApplyDiscountCode::claim($first->refresh()))->toBeTrue();
    });
})->group('fast');
