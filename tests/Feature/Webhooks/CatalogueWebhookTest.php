<?php

declare(strict_types=1);

use App\Domain\Webhooks\Support\WebhookSignature;
use App\Enums\ProductStatus;
use App\Enums\WebhookEvent;
use App\Jobs\DeliverWebhook;
use App\Jobs\PublishProductChange;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Tenancy;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\travel;

/*
|--------------------------------------------------------------------------
| The catalogue events — product.published, product.updated, product.unpublished
|--------------------------------------------------------------------------
|
| Added so a website mirroring the catalogue (the WordPress plugin's trip
| pages) hears that a trip changed, rather than waiting for the hourly sync.
| Weighted towards the two ways this goes quietly wrong: one form save arriving
| as an unpublish-then-publish pair, and a change that is never sent at all.
|
*/

function catalogueTenant(): Tenant
{
    return Tenant::factory()->create();
}

/** Subscribed to everything, the factory's default. */
function catalogueEndpoint(Tenant $tenant): WebhookEndpoint
{
    return Tenancy::forTenant($tenant, fn (): WebhookEndpoint => WebhookEndpoint::factory()->create());
}

/**
 * A trip, with the creation's uniqueness lock let go again.
 *
 * Creating a trip queues its own `PublishProductChange`, which takes the job's
 * unique lock. On the sync queue the job runs at once and releases it; on
 * Redis (the MySQL CI job) it sits in the queue and the lock stays for two
 * minutes, so the save each test is really about would be silenced by the
 * setup. Releasing it here makes the tests about the change, on either queue.
 *
 * @param  array<string, mixed>  $attributes
 */
function catalogueProduct(Tenant $tenant, array $attributes = []): Product
{
    $product = Tenancy::forTenant($tenant, fn (): Product => Product::factory()->create($attributes));

    (new UniqueLock(Cache::store()))->release(new PublishProductChange(
        (int) $product->getKey(),
        (int) $product->tenant_id,
        (string) $product->uuid,
        (string) $product->slug,
        false,
    ));

    return $product;
}

/** @return list<WebhookDelivery> */
function catalogueDeliveries(Tenant $tenant): array
{
    return Tenancy::forTenant($tenant, fn (): array => WebhookDelivery::query()->orderBy('id')->get()->all());
}

/** Run the job the observer would have queued, as the worker would. */
function runProductChange(Product $product, bool $wasPublic): void
{
    app()->call([new PublishProductChange(
        (int) $product->getKey(),
        (int) $product->tenant_id,
        (string) $product->uuid,
        (string) $product->slug,
        $wasPublic,
    ), 'handle']);
}

it('names the transition by where the trip started and where it ended', function (bool $was, bool $is, ?WebhookEvent $expected): void {
    expect(PublishProductChange::eventFor($was, $is))->toBe($expected);
})->with([
    'hidden to visible' => [false, true, WebhookEvent::ProductPublished],
    'visible to visible' => [true, true, WebhookEvent::ProductUpdated],
    'visible to hidden' => [true, false, WebhookEvent::ProductUnpublished],
    'hidden to hidden' => [false, false, null],
]);

it('queues one delayed job when a trip is created', function (): void {
    Queue::fake();

    $tenant = catalogueTenant();
    $product = catalogueProduct($tenant);

    Queue::assertPushed(PublishProductChange::class, function (PublishProductChange $job) use ($product): bool {
        return $job->productId === $product->getKey()
            && $job->wasPublic === false
            && $job->delay === PublishProductChange::COALESCE_SECONDS;
    });
});

it('queues one job for two saves of the same trip, keeping where it started', function (): void {
    $tenant = catalogueTenant();
    $product = catalogueProduct($tenant);

    Queue::fake();

    // What the panel's form does when an operator saves a published trip:
    // down to draft, then back to active once the age bands are in.
    Tenancy::forTenant($tenant, function () use ($product): void {
        $product->update(['status' => ProductStatus::Draft, 'badge' => ['el' => 'Νέο', 'en' => 'New']]);
        $product->update(['status' => ProductStatus::Active]);
    });

    Queue::assertPushed(PublishProductChange::class, 1);
    Queue::assertPushed(PublishProductChange::class, fn (PublishProductChange $job): bool => $job->wasPublic === true);
});

it('queues nothing for a touch', function (): void {
    $tenant = catalogueTenant();
    $product = catalogueProduct($tenant);

    Queue::fake();

    travel(5)->minutes();
    Tenancy::forTenant($tenant, fn () => $product->touch());

    Queue::assertNotPushed(PublishProductChange::class);
});

it('queues nothing when the save rolls back, and holds no lock afterwards', function (): void {
    $tenant = catalogueTenant();
    $product = catalogueProduct($tenant);

    Queue::fake();

    try {
        DB::transaction(function () use ($tenant, $product): void {
            Tenancy::forTenant($tenant, fn () => $product->update(['title' => ['el' => 'Άλλο', 'en' => 'Other']]));

            throw new RuntimeException('the publishing checklist said no');
        });
    } catch (RuntimeException) {
        // Expected.
    }

    Queue::assertNotPushed(PublishProductChange::class);

    // The next real change still gets through.
    Tenancy::forTenant($tenant, fn () => $product->update(['title' => ['el' => 'Τρίτο', 'en' => 'Third']]));

    Queue::assertPushed(PublishProductChange::class, 1);
});

it('sends product.updated for a visible trip that changed', function (): void {
    Queue::fake();

    $tenant = catalogueTenant();
    catalogueEndpoint($tenant);
    $product = catalogueProduct($tenant, ['highlights' => ['el' => ['Κολύμπι'], 'en' => ['Swimming']]]);

    runProductChange($product, wasPublic: true);

    $deliveries = catalogueDeliveries($tenant);

    expect($deliveries)->toHaveCount(1)
        ->and($deliveries[0]->event)->toBe(WebhookEvent::ProductUpdated)
        ->and($deliveries[0]->payload['event'])->toBe('product.updated')
        ->and($deliveries[0]->payload['is_test'])->toBeFalse()
        ->and($deliveries[0]->payload['data']['product'])->toMatchArray([
            'uuid' => $product->uuid,
            'slug' => $product->slug,
            'status' => 'active',
            'deleted' => false,
        ])
        // A signal, not a second copy of the catalogue.
        ->and($deliveries[0]->payload['data']['product'])->not->toHaveKey('highlights');

    Queue::assertPushed(DeliverWebhook::class, 1);
});

it('sends product.published for a trip switched on', function (): void {
    Queue::fake();

    $tenant = catalogueTenant();
    catalogueEndpoint($tenant);
    $product = catalogueProduct($tenant);

    runProductChange($product, wasPublic: false);

    expect(catalogueDeliveries($tenant)[0]->event)->toBe(WebhookEvent::ProductPublished);
});

it('sends product.unpublished for a trip deleted, flagged as deleted', function (): void {
    $tenant = catalogueTenant();
    $product = catalogueProduct($tenant);
    catalogueEndpoint($tenant);

    Queue::fake();

    Tenancy::forTenant($tenant, fn () => $product->delete());

    Queue::assertPushed(PublishProductChange::class, fn (PublishProductChange $job): bool => $job->wasPublic === true);

    runProductChange($product, wasPublic: true);

    $delivery = catalogueDeliveries($tenant)[0];

    expect($delivery->event)->toBe(WebhookEvent::ProductUnpublished)
        ->and($delivery->payload['data']['product']['deleted'])->toBeTrue()
        ->and($delivery->payload['data']['product']['uuid'])->toBe($product->uuid);
});

it('still names the page to take down when the trip is gone for good', function (): void {
    Queue::fake();

    $tenant = catalogueTenant();
    catalogueEndpoint($tenant);
    $product = catalogueProduct($tenant);

    Tenancy::forTenant($tenant, fn () => $product->forceDelete());

    runProductChange($product, wasPublic: true);

    $delivery = catalogueDeliveries($tenant)[0];

    expect($delivery->event)->toBe(WebhookEvent::ProductUnpublished)
        ->and($delivery->payload['data']['product'])->toMatchArray([
            'uuid' => $product->uuid,
            'slug' => $product->slug,
            'deleted' => true,
        ]);
});

it('sends nothing for a draft edited while still a draft', function (): void {
    Queue::fake();

    $tenant = catalogueTenant();
    catalogueEndpoint($tenant);
    $product = catalogueProduct($tenant, ['status' => ProductStatus::Draft]);

    runProductChange($product, wasPublic: false);

    expect(catalogueDeliveries($tenant))->toBe([]);
});

it('sends catalogue events only to endpoints that ticked them', function (): void {
    Queue::fake();

    $tenant = catalogueTenant();
    Tenancy::forTenant($tenant, fn () => WebhookEndpoint::factory()->subscribedTo(WebhookEvent::BookingConfirmed)->create());
    $product = catalogueProduct($tenant);

    runProductChange($product, wasPublic: true);

    expect(catalogueDeliveries($tenant))->toBe([]);
});

it('posts a signed product.updated when a published trip is edited, end to end', function (): void {
    // End to end means the jobs run inline, whichever queue the job is on.
    config(['queue.default' => 'sync']);
    Http::fake(['*' => Http::response('ok', 200)]);

    $tenant = catalogueTenant();
    $product = catalogueProduct($tenant);
    $endpoint = Tenancy::forTenant($tenant, fn () => WebhookEndpoint::factory()->subscribedTo(
        WebhookEvent::ProductPublished,
        WebhookEvent::ProductUpdated,
        WebhookEvent::ProductUnpublished,
    )->create());

    // The sync queue runs both jobs inline: the coalescing one, then delivery.
    Tenancy::forTenant($tenant, fn () => $product->update(['badge' => ['el' => 'Δημοφιλές', 'en' => 'Popular']]));

    Http::assertSentCount(1);
    Http::assertSent(function ($request) use ($endpoint, $product): bool {
        $timestamp = (int) $request->header('Kaiki-Timestamp')[0];
        $body = json_decode($request->body(), true);

        return $request->url() === $endpoint->url
            && $request->header('Kaiki-Event')[0] === 'product.updated'
            && str_starts_with($request->header('Kaiki-Signature')[0], 'v1=')
            && $body['data']['product']['uuid'] === $product->uuid
            && WebhookSignature::verify(
                $endpoint->signing_secret,
                $request->header('Kaiki-Signature')[0],
                $timestamp,
                $request->body(),
                $timestamp,
            );
    });
});
