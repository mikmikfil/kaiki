<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\WebhookEvent;
use App\Events\BookingCancelled;
use App\Events\BookingConfirmed;
use App\Events\DepartureCancelled;
use App\Events\GuestDetailsCompleted;
use App\Listeners\Webhooks\PublishDomainEvent;
use App\Models\Product;
use App\Observers\ProductWebhookObserver;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * The events OPS-19 publishes, wired explicitly.
 *
 * ## Explicit rather than discovered, and the reason is the contract
 *
 * Laravel would find `PublishDomainEvent` by convention if it took a typed
 * event argument. It takes `object` on purpose — it handles four — and even if
 * it did not, discovery is the wrong mechanism here. `docs/api.md` §8.1 fixes
 * the published list, and a convention that quietly
 * subscribes a fifth the day somebody adds a matching method would turn an
 * internal event into a public contract without anybody deciding to.
 *
 * This file is therefore the list, and it is meant to be read alongside
 * {@see WebhookEvent}: one says what may be sent, the other says what
 * causes it.
 *
 * The three `product.*` events come from a model observer rather than a domain
 * event, because a trip changes through more writers than any one action — and
 * the observer is registered here, not with `#[ObservedBy]` on the model, so
 * this file stays the whole list.
 */
class WebhookServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach ([
            BookingConfirmed::class,
            BookingCancelled::class,
            DepartureCancelled::class,
            GuestDetailsCompleted::class,
        ] as $event) {
            Event::listen($event, PublishDomainEvent::class);
        }

        Product::observe(ProductWebhookObserver::class);
    }
}
