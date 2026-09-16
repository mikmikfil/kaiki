<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Webhooks\Actions\DispatchWebhookEvent;
use App\Domain\Webhooks\Support\WebhookPayload;
use App\Enums\ProductStatus;
use App\Enums\WebhookEvent;
use App\Models\Product;
use App\Models\Tenant;
use App\Observers\ProductWebhookObserver;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A trip changed; decide which of the three catalogue events that was, and send it.
 *
 * ## Decided when it runs, not when the save happened
 *
 * One press of «Αποθήκευση» on a published trip is **two** saves: the form
 * stores the trip as a draft, saves its age bands, and only then switches it
 * back to `active` so the publishing checklist judges the finished thing. Sent
 * save by save, that is `product.unpublished` followed by `product.published`
 * for an edit that never took anything down — and, because §8.4 does not
 * promise order, a receiver could see them the other way round.
 *
 * So {@see ProductWebhookObserver} records only where the trip stood *before*
 * the first save (`$wasPublic`) and queues this a few seconds later. It is
 * unique per product until it starts, so the second save of the same press
 * finds a job already waiting and adds nothing. When it runs it compares that
 * starting point with the trip as it is now:
 *
 * | before  | now     | event                  |
 * |---------|---------|------------------------|
 * | hidden  | visible | `product.published`    |
 * | visible | visible | `product.updated`      |
 * | visible | hidden  | `product.unpublished`  |
 * | hidden  | hidden  | nothing                |
 *
 * The last row is deliberate. A draft edited forty times is of no interest to
 * a website, and the hourly sync still carries it the day it goes live.
 *
 * ## Until processing, not until finished
 *
 * A change made while this job is running must queue a job of its own; if the
 * lock were held until the end, that change would be dropped on the floor.
 */
final class PublishProductChange implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** Long enough to cover one form save; short enough to still be «live». */
    public const COALESCE_SECONDS = 10;

    /** A lock that outlives a lost job would silence the product; this bounds it. */
    public int $uniqueFor = 120;

    public function __construct(
        public readonly int $productId,
        public readonly int $tenantId,
        public readonly string $uuid,
        public readonly string $slug,
        public readonly bool $wasPublic,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->productId;
    }

    public function handle(DispatchWebhookEvent $dispatch): void
    {
        $tenant = Tenancy::withoutTenancy(
            fn (): ?Tenant => Tenant::query()->find($this->tenantId),
        );

        if (! $tenant instanceof Tenant) {
            return;
        }

        Tenancy::forTenant($tenant, function () use ($tenant, $dispatch): void {
            $product = Product::query()->withTrashed()->find($this->productId);

            $event = self::eventFor($this->wasPublic, self::isPublic($product));

            if ($event === null) {
                return;
            }

            $dispatch(
                $tenant,
                $event,
                WebhookPayload::product($product, $this->uuid, $this->slug),
                false,
            );
        });
    }

    /** What a guest could see: switched on and not deleted. The same rule as `sellable()`. */
    public static function isPublic(?Product $product): bool
    {
        return $product instanceof Product
            && ! $product->trashed()
            && $product->status === ProductStatus::Active;
    }

    /** The table in the class docblock, as code. */
    public static function eventFor(bool $wasPublic, bool $isPublic): ?WebhookEvent
    {
        return match (true) {
            ! $wasPublic && $isPublic => WebhookEvent::ProductPublished,
            $wasPublic && $isPublic => WebhookEvent::ProductUpdated,
            $wasPublic && ! $isPublic => WebhookEvent::ProductUnpublished,
            default => null,
        };
    }
}
