<?php

declare(strict_types=1);

namespace App\Domain\Webhooks;

use App\Enums\WebhookEvent;

/**
 * What an endpoint is allowed to be subscribed to (`docs/api.md` §8.1, §3.13).
 *
 * ## Named in the contract, so it exists by that name
 *
 * `docs/api.md` §8.1 says outright: *"Unknown event names are rejected at save
 * time against `app/Domain/Webhooks/EventRegistry.php`, so a typo cannot
 * silently disable a subscription."* That sentence is a promise to an
 * integrator reading the contract, and a file path in a published document is
 * part of it.
 *
 * ## Why a registry at all, when there is already an enum
 *
 * {@see WebhookEvent} is the list. This is what happens at the boundary, and
 * the boundary is a **JSON column an operator writes into** — the one place in
 * the schema where a future mistake arrives quietly: somebody adds a form
 * field, it lands in the blob, a job starts reading it, and now there is an
 * undocumented event with no lang key and no listener.
 *
 * So `normalise()` runs on the way in **and on the way out**, exactly as
 * `BlockSettings` does for home-page blocks. An endpoint stored against an event
 * that has since been withdrawn simply stops matching it, rather than throwing
 * on a queue worker at three in the morning.
 *
 * ## A typo is refused; a withdrawn event is dropped
 *
 * The two are different and the difference matters. `accepts()` is the
 * validation rule the form uses — `booking.confirmd` is a mistake somebody can
 * still fix, and telling them is the whole point. `normalise()` is the read
 * path, where refusing would break rows written against an older shape; there,
 * an unknown name is silently dropped.
 */
final class EventRegistry
{
    /**
     * Every event an operator may subscribe to, in a stable order.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return WebhookEvent::names();
    }

    /** Is this a name we will ever send? */
    public static function accepts(string $name): bool
    {
        return WebhookEvent::tryFrom($name) !== null;
    }

    /**
     * A stored subscription list, cleaned up.
     *
     * Unknown names dropped, duplicates removed, declaration order restored —
     * so two endpoints subscribed to the same two events compare equal however
     * the operator ticked the boxes.
     *
     * @param  array<array-key, mixed>|null  $stored
     * @return list<string>
     */
    public static function normalise(?array $stored): array
    {
        $wanted = [];

        foreach ((array) $stored as $name) {
            if (is_string($name) && self::accepts($name)) {
                $wanted[$name] = true;
            }
        }

        return array_values(array_filter(
            self::names(),
            static fn (string $name): bool => isset($wanted[$name]),
        ));
    }

    /**
     * Does this endpoint want to hear about this event?
     *
     * @param  array<array-key, mixed>|null  $subscribed
     */
    public static function wants(?array $subscribed, WebhookEvent $event): bool
    {
        return in_array($event->value, self::normalise($subscribed), true);
    }
}
