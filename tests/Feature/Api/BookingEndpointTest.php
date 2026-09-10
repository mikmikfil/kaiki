<?php

declare(strict_types=1);

use App\Enums\ApiKeyType;
use App\Enums\BookingStatus;
use App\Enums\TenantStatus;
use App\Models\Booking;
use App\Support\Tenancy;
use Illuminate\Support\Arr;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

use Tests\Support\Api\BookingApiScenario;
use Tests\Support\Api\CatalogRequest;

/*
|--------------------------------------------------------------------------
| The four booking endpoints — docs/api.md §5, spec BKG-9, SAA-7, CNV-8
|--------------------------------------------------------------------------
|
| This is the write half of the public API, and three of its assertions are
| about restraint rather than behaviour:
|
|   CNV-8   no database id appears anywhere in a payload, asserted
|           **recursively** the way `ProductIndexTest` does it. A rule about
|           what leaves is kept by a scanner or it is not kept.
|
|   §5      `manage_token` is returned on the `201` and never again. The one
|           moment a client can capture it, and every later read must be null —
|           a token in a read is a cancel-and-refund credential in a log.
|
|   SAA-7   a read-only tenant refuses these **writes** while the catalogue and
|           availability reads (#36, #37) stay open. A lapsed subscription stops
|           an operator taking new money; it never punishes a guest who already
|           holds a booking.
|
*/

/**
 * Every scalar in a payload, flattened.
 *
 * @param  array<array-key, mixed>  $payload
 * @return list<array{0: string, 1: mixed}>
 */
function flattenBookingPayload(array $payload, string $prefix = ''): array
{
    $flat = [];

    foreach ($payload as $key => $value) {
        $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

        if (is_array($value)) {
            $flat = [...$flat, ...flattenBookingPayload($value, $path)];

            continue;
        }

        $flat[] = [$path, $value];
    }

    return $flat;
}

it('creates a draft booking and places a hold', function (): void {
    $fixture = BookingApiScenario::bookable();

    $response = postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band']),
        [
            'Authorization' => "Bearer {$fixture['key']}",
            'Idempotency-Key' => BookingApiScenario::idempotencyKey(),
        ],
    );

    $response->assertCreated()
        ->assertJsonPath('data.status', BookingStatus::Draft->value)
        ->assertJsonPath('data.pax_total', 2)
        // §5: the deadline the client shows a countdown against.
        ->assertJsonPath('data.money.total_cents', 13000);

    // §3.1's frozen line, and the contract's `PaxBreakdownLine`. The **label**
    // is frozen per locale so a confirmation from last season still renders
    // after the operator renames the band, and the prices come from the price
    // snapshot rather than being recomputed — corrected by #89, which found the
    // line carrying an integer `age_band_id` and none of the rest.
    expect($response->json('data.pax.0.age_band_uuid'))->toBe($fixture['band']->uuid)
        ->and($response->json('data.pax.0.unit_price_cents'))->toBe(6500)
        ->and($response->json('data.pax.0.total_cents'))->toBe(13000)
        ->and($response->json('data.pax.0.label'))->toBeArray();

    expect($response->json('data.hold_expires_at'))->not->toBeNull()
        // §3.6: a booking must never sit in a shared cache, and this one has a
        // credential in its body.
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Location'))->toContain('/b/');

    Tenancy::forTenant($fixture['tenant'], function (): void {
        expect(Booking::query()->count())->toBe(1);
    });
})->group('fast');

it('returns the manage token on creation and never again', function (): void {
    $fixture = BookingApiScenario::bookable();

    $created = postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band']),
        ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => BookingApiScenario::idempotencyKey()],
    );

    $token = $created->json('data.manage_token');
    $uuid = $created->json('data.uuid');

    // §5: "this is the one moment the client can capture it."
    expect($token)->toBeString()->and(strlen((string) $token))->toBe(40);

    $read = getJson(
        CatalogRequest::url("/bookings/{$uuid}"),
        ['Authorization' => "Bearer {$fixture['key']}", 'X-Kaiki-Guest-Token' => $token],
    );

    // "Always null on subsequent reads; the caller already holds it."
    $read->assertOk()->assertJsonPath('data.manage_token', null);
})->group('fast');

it('puts no database id anywhere in a booking payload', function (): void {
    $fixture = BookingApiScenario::bookable();

    $response = postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band']),
        ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => BookingApiScenario::idempotencyKey()],
    );

    $offenders = [];

    foreach (flattenBookingPayload((array) $response->json('data')) as [$path, $value]) {
        $leaf = str_contains($path, '.') ? substr((string) strrchr($path, '.'), 1) : $path;

        // CNV-8: "integer primary keys never appear in an API response, an
        // email, a URL or a widget payload." Asserted by **shape** — any key
        // called `id` or ending `_id` — rather than by listing the fields this
        // resource happens to render today.
        if ($leaf === 'id' || str_ends_with($leaf, '_id')) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([], 'database ids in the payload: ' . implode(', ', $offenders));
})->group('fast');

it('reads a booking back with its own token', function (): void {
    $fixture = BookingApiScenario::bookable();

    $created = postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band']),
        ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => BookingApiScenario::idempotencyKey()],
    );

    getJson(
        CatalogRequest::url('/bookings/' . $created->json('data.uuid')),
        [
            'Authorization' => "Bearer {$fixture['key']}",
            'X-Kaiki-Guest-Token' => $created->json('data.manage_token'),
        ],
    )
        ->assertOk()
        ->assertJsonPath('data.reference', $created->json('data.reference'))
        // BKG-22's window, on the payload a guest reads on the morning itself.
        ->assertJsonPath('data.check_in_local_time', $created->json('data.check_in_local_time'));
})->group('fast');

it('starts a checkout and hands back a gateway url', function (): void {
    $fixture = BookingApiScenario::bookable();

    $created = postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band']),
        ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => BookingApiScenario::idempotencyKey()],
    );

    $response = postJson(
        CatalogRequest::url('/bookings/' . $created->json('data.uuid') . '/checkout'),
        ['kind' => 'full', 'return_url' => 'https://aegeancruises.gr/thanks'],
        [
            'Authorization' => "Bearer {$fixture['key']}",
            'X-Kaiki-Guest-Token' => $created->json('data.manage_token'),
            'Idempotency-Key' => BookingApiScenario::idempotencyKey(),
        ],
    );

    $response->assertCreated()
        ->assertJsonPath('data.kind', 'full')
        ->assertJsonPath('data.amount_cents', 13000);

    expect($response->json('data.redirect_url'))->toBeString()
        // The schema: "Short-lived; do not cache or email it."
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');

    Tenancy::forTenant($fixture['tenant'], function () use ($created): void {
        // BKG-9: seats move at redirect, not at the webhook.
        expect(Booking::query()->where('uuid', $created->json('data.uuid'))->sole()->status)
            ->toBe(BookingStatus::PendingPayment);
    });
})->group('fast');

it('previews a cancellation without cancelling anything', function (): void {
    $fixture = BookingApiScenario::bookable(startsAt: now()->addDays(60)->setTime(9, 0));

    $created = postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band']),
        ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => BookingApiScenario::idempotencyKey()],
    );

    $booking = Tenancy::forTenant(
        $fixture['tenant'],
        fn (): Booking => Booking::query()->where('uuid', $created->json('data.uuid'))->sole(),
    );

    BookingApiScenario::confirm($fixture['tenant'], $booking, 13000);

    $response = postJson(
        CatalogRequest::url('/bookings/' . $booking->uuid . '/cancel'),
        ['dry_run' => true],
        [
            'Authorization' => "Bearer {$fixture['key']}",
            'X-Kaiki-Guest-Token' => $created->json('data.manage_token'),
            'Idempotency-Key' => BookingApiScenario::idempotencyKey(),
        ],
    );

    // The schema: "A dry run has no side effects and returns the same
    // CancellationResult shape with performed: false."
    $response->assertOk()
        ->assertJsonPath('data.performed', false)
        ->assertJsonPath('data.status', BookingStatus::Confirmed->value);

    Tenancy::forTenant($fixture['tenant'], function () use ($booking): void {
        expect($booking->refresh()->status)->toBe(BookingStatus::Confirmed);
    });
})->group('fast');

it('cancels for real and shows the guest the same number twice', function (): void {
    $fixture = BookingApiScenario::bookable(startsAt: now()->addDays(60)->setTime(9, 0));

    $created = postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band']),
        ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => BookingApiScenario::idempotencyKey()],
    );

    $booking = Tenancy::forTenant(
        $fixture['tenant'],
        fn (): Booking => Booking::query()->where('uuid', $created->json('data.uuid'))->sole(),
    );

    BookingApiScenario::confirm($fixture['tenant'], $booking, 13000);

    $headers = [
        'Authorization' => "Bearer {$fixture['key']}",
        'X-Kaiki-Guest-Token' => $created->json('data.manage_token'),
    ];

    $preview = postJson(
        CatalogRequest::url('/bookings/' . $booking->uuid . '/cancel'),
        ['dry_run' => true],
        [...$headers, 'Idempotency-Key' => BookingApiScenario::idempotencyKey()],
    );

    $performed = postJson(
        CatalogRequest::url('/bookings/' . $booking->uuid . '/cancel'),
        ['dry_run' => false],
        [...$headers, 'Idempotency-Key' => BookingApiScenario::idempotencyKey()],
    );

    // The one thing a guest will actually check: the figure they were shown
    // and the figure they got. Both come from the same frozen policy snapshot
    // (CXL-1) through the same `RefundEntitlement`.
    $performed->assertOk()
        ->assertJsonPath('data.performed', true)
        ->assertJsonPath('data.refund.amount_cents', $preview->json('data.refund.amount_cents'))
        ->assertJsonPath('data.status', BookingStatus::Cancelled->value);

    // The schema: the refund is queued, so it is `pending` here and the
    // booking moves to `refunded` only when the webhook settles.
    expect($performed->json('data.refund.status'))->toBeIn(['pending', 'not_applicable']);
})->group('fast');

it('refuses the writes for a read-only tenant and leaves the reads open', function (): void {
    $fixture = BookingApiScenario::bookable();

    // SAA-7. #36 and #37 deliberately left the catalogue and availability reads
    // open for the same requirement — the pressure lands on the operator who
    // owes money, never on the tourist holding a ticket.
    $fixture['tenant']->forceFill(['status' => TenantStatus::ReadOnly])->save();

    postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band']),
        ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => BookingApiScenario::idempotencyKey()],
    )
        ->assertForbidden()
        ->assertJsonPath('error.code', 'tenant_read_only');

    getJson(
        CatalogRequest::url('/products'),
        ['Authorization' => "Bearer {$fixture['key']}"],
    )->assertOk();
})->group('fast');

it('refuses a product that does not exist', function (): void {
    $fixture = BookingApiScenario::bookable();

    postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band'], [
            'product_uuid' => '00000000-0000-4000-8000-000000000000',
        ]),
        ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => BookingApiScenario::idempotencyKey()],
    )
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
})->group('fast');

it('refuses a back-office source from the public api', function (): void {
    $fixture = BookingApiScenario::bookable();

    // The schema: "`manual` and `import` are back-office only and are rejected
    // here." Rejected rather than filtered — a request claiming to be a manual
    // booking is either a client bug or an attempt to skip BKG-32's rules, and
    // rewriting it to `widget` would hide both.
    postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band'], [
            'source' => 'manual',
        ]),
        ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => BookingApiScenario::idempotencyKey()],
    )
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
})->group('fast');

it('creates a draft nobody has put their name to yet', function (): void {
    $fixture = BookingApiScenario::bookable();

    // ADR-0030. A draft is a hold on seats: the widget asks for a date and a
    // party, and the lead guest is typed on the checkout page the response
    // points at.
    $created = postJson(
        CatalogRequest::url('/bookings'),
        Arr::except(
            BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band']),
            ['guest', 'terms_accepted'],
        ),
        ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => BookingApiScenario::idempotencyKey()],
    )->assertCreated();

    // The address the widget sends them to. It contains the manage token, so it
    // appears in the `201` and nowhere else — like the token itself.
    expect($created->json('data.checkout_url'))
        ->toBeString()
        ->toContain($created->json('data.manage_token'));

    Tenancy::forTenant($fixture['tenant'], function () use ($created): void {
        $booking = Booking::query()->where('uuid', $created->json('data.uuid'))->sole();

        // Null, not an empty string. `''` is a name; null is a fact.
        expect($booking->guest_name)->toBeNull()
            ->and($booking->guest_email)->toBeNull()
            ->and($booking->terms_accepted_at)->toBeNull()
            // The seats are held all the same — that is what the draft is for,
            // and the hold covers the minutes the checkout form takes.
            ->and($booking->hold_expires_at)->not->toBeNull()
            ->and($booking->status)->toBe(BookingStatus::Draft);
    });
})->group('fast');

it('refuses to start a checkout for a booking nobody has claimed', function (): void {
    $fixture = BookingApiScenario::bookable();

    $created = postJson(
        CatalogRequest::url('/bookings'),
        Arr::except(
            BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band']),
            ['guest', 'terms_accepted'],
        ),
        ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => BookingApiScenario::idempotencyKey()],
    )->assertCreated();

    // ADR-0030's invariant, and the whole reason a draft may be anonymous: the
    // rule moved from «no draft without a lead guest» to «no payment without
    // one». Nothing reaches a gateway, an invoice or a manifest unnamed.
    postJson(
        CatalogRequest::url('/bookings/' . $created->json('data.uuid') . '/checkout'),
        ['kind' => 'full', 'return_url' => 'https://aegeancruises.gr/thanks'],
        [
            'Authorization' => "Bearer {$fixture['key']}",
            'X-Kaiki-Guest-Token' => $created->json('data.manage_token'),
            'Idempotency-Key' => BookingApiScenario::idempotencyKey(),
        ],
    )
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'lead_guest_required');

    Tenancy::forTenant($fixture['tenant'], function () use ($created): void {
        // And the seats stayed held rather than being committed on the way to a
        // refusal.
        expect(Booking::query()->where('uuid', $created->json('data.uuid'))->sole()->status)
            ->toBe(BookingStatus::Draft);
    });
})->group('fast');

it('refuses a booking with the terms unaccepted', function (): void {
    $fixture = BookingApiScenario::bookable();

    // GDR-9. The consent is what makes the cancellation policy enforceable, and
    // a booking without it is a dispute waiting to happen.
    postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band'], [
            'terms_accepted' => false,
        ]),
        ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => BookingApiScenario::idempotencyKey()],
    )->assertStatus(422);
})->group('fast');

it('lets a secret key read a booking with no guest token', function (): void {
    $fixture = BookingApiScenario::bookable(keyType: ApiKeyType::Secret);

    $created = postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band']),
        ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => BookingApiScenario::idempotencyKey()],
    );

    // §2.1's table: an `sk_` is the operator acting on their own data, and it
    // is server-side by definition — the reason a `pk_` is refused does not
    // apply to it.
    getJson(
        CatalogRequest::url('/bookings/' . $created->json('data.uuid')),
        ['Authorization' => "Bearer {$fixture['key']}"],
    )->assertOk();
})->group('fast');
