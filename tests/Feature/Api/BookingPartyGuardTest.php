<?php

declare(strict_types=1);

use App\Enums\AvailabilityRejection;
use App\Models\AgeBand;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Tenancy;

use function Pest\Laravel\postJson;

use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Tests\Support\Api\BookingApiScenario;
use Tests\Support\Api\CatalogRequest;

/*
|--------------------------------------------------------------------------
| POST /bookings asks the party rules — AVL-26, AVL-26b
|--------------------------------------------------------------------------
|
| `GET /availability` greys the party out and `POST /price-quote` refuses to
| price it, and **neither stops a client posting it anyway**: the widget runs on
| somebody else's page, and the endpoint is public. Until 2026-09-22 the write
| path asked only whether seats were free, so a party of two children was
| accepted, priced, held and handed a payment link.
|
| The status is 422 rather than 409: the party is wrong, not the timing, and
| retrying the same body will never succeed. §5 reserves the 409 for seats that
| went while the guest was deciding.
|
*/

/**
 * The fixture plus a child band — takes a seat, needs an adult.
 *
 * @return array{tenant: Tenant, product: Product, departure: Departure, band: AgeBand, key: string, child: AgeBand}
 */
function partyFixtureWithChild(): array
{
    $fixture = BookingApiScenario::bookable();

    $child = Tenancy::forTenant($fixture['tenant'], static function () use ($fixture): AgeBand {
        /** @var Product $product */
        $product = $fixture['product'];

        return AgeBand::factory()->child()->create(['product_id' => $product->getKey()]);
    });

    return [...$fixture, 'child' => $child];
}

it('refuses a party of children with no adult, and writes nothing', function (): void {
    $fixture = partyFixtureWithChild();

    $response = postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band'], [
            'pax' => [['age_band_uuid' => $fixture['child']->uuid, 'qty' => 2]],
        ]),
        [
            'Authorization' => "Bearer {$fixture['key']}",
            'Idempotency-Key' => BookingApiScenario::idempotencyKey(),
        ],
    );

    $response->assertStatus(SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonPath('error.code', AvailabilityRejection::NeedsAdult->value)
        // Both languages in every API error (docs/api.md §4.1): a guest can hit
        // a refusal mid-locale-switch, and one string is one language wrong.
        ->assertJsonPath('error.message_el', AvailabilityRejection::NeedsAdult->labelIn('el'))
        ->assertJsonPath('error.message', AvailabilityRejection::NeedsAdult->labelIn('en'));

    // Refused **before** the draft, so there is no row, no reference burned and
    // no hold to expire. A booking written and then rolled back would still
    // have taken a reference out of the one index whose collisions matter.
    Tenancy::forTenant($fixture['tenant'], function (): void {
        expect(Booking::query()->count())->toBe(0);
    });
});

it('still accepts the same children once an adult is in the party', function (): void {
    $fixture = partyFixtureWithChild();

    $response = postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band'], [
            'pax' => [
                ['age_band_uuid' => $fixture['band']->uuid, 'qty' => 1],
                ['age_band_uuid' => $fixture['child']->uuid, 'qty' => 1],
            ],
        ]),
        [
            'Authorization' => "Bearer {$fixture['key']}",
            'Idempotency-Key' => BookingApiScenario::idempotencyKey(),
        ],
    );

    // The guard is a refusal for one shape of party and invisible for every
    // other — a rule that also broke the ordinary booking would be worse than
    // the bug it fixes.
    $response->assertCreated()
        ->assertJsonPath('data.pax_total', 2);
});

it('refuses a party of infants alone with the older code', function (): void {
    $fixture = BookingApiScenario::bookable();

    $infant = Tenancy::forTenant($fixture['tenant'], static function () use ($fixture): AgeBand {
        /** @var Product $product */
        $product = $fixture['product'];

        return AgeBand::factory()->infant()->create(['product_id' => $product->getKey()]);
    });

    $response = postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band'], [
            'pax' => [['age_band_uuid' => $infant->uuid, 'qty' => 2]],
        ]),
        [
            'Authorization' => "Bearer {$fixture['key']}",
            'Idempotency-Key' => BookingApiScenario::idempotencyKey(),
        ],
    );

    // AVL-26, which the write path did not ask either. Its own code, because
    // the two send a guest to the same remedy by different sentences.
    $response->assertStatus(SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonPath('error.code', AvailabilityRejection::NoCountedPax->value);
});
