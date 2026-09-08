<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One attempt-set per event per endpoint (spec OPS-20, `docs/api.md` §8.4).
 *
 * Item 42 of `docs/data-model.md` §6.
 *
 * ## The unique index is the delivery guarantee, not an optimisation
 *
 * `(webhook_endpoint_id, event_id)` unique. A queued job that runs twice —
 * which happens: a worker dies after the HTTP call and before the ack — must
 * not fire a second POST at somebody's accounting system. The database refuses
 * the second row, so the duplicate job finds the delivery already recorded and
 * stops. It is the same shape as the idempotency key on `POST /bookings`, and
 * for the same reason: at-least-once is what a queue offers, and exactly-once
 * is something you build on top of it with a unique constraint.
 *
 * `event_id` is also what the *receiver* deduplicates on — it goes out as
 * `Kaiki-Delivery-Id` and is **stable across retries** (§8.4). A consumer that
 * stores it and ignores repeats is safe; one that assumes exactly-once
 * double-books something.
 *
 * ## `payload` stores what was sent, not what to send
 *
 * Written once, when the delivery is created, and never recomputed on retry.
 * A booking cancelled between attempt one and attempt six must not turn a
 * `booking.confirmed` into a POST describing a cancelled booking — the event
 * says what was true when it happened, and the second event says the rest.
 * Rebuilding the payload per attempt would silently rewrite history and make
 * the retries disagree with each other.
 *
 * ## The retry index is deliberately cross-tenant
 *
 * `(status, next_attempt_at)` with no `tenant_id` in front. The sweeper asks
 * "what is due anywhere", the way `ical_sources_sync_idx` and the hold-expiry
 * sweeper do; a tenant-first index would make it scan once per operator.
 * §1.4 permits this for platform-level jobs and requires it be named.
 *
 * ## Pruned at 90 days
 *
 * The history is for answering "what did we send" while somebody still cares,
 * and a delivery log is a copy of booking data — a guest's name and email in a
 * second table, growing forever, retained for no stated purpose. Ninety days is
 * long enough for the question and short enough to be defensible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('webhook_endpoint_id')->constrained('webhook_endpoints')->cascadeOnDelete();

            // `booking.confirmed` and the other three of §8.1.
            $table->string('event', 48);

            // The uuid of the event instance, and the receiver's idempotency key.
            $table->char('event_id', 36);

            $table->json('payload');

            // `pending` | `delivered` | `failed` | `abandoned` — PHP enum
            // `DeliveryStatus`, never a MySQL ENUM (§1.2).
            $table->string('status', 16)->default('pending');

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();

            $table->unsignedSmallInteger('response_status')->nullable();

            // Truncated. Somebody's 500 page is an entire HTML document, and
            // the useful part of it is the first paragraph.
            $table->string('response_body', 2000)->nullable();

            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('delivered_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'webhook_endpoint_id', 'created_at'], 'wh_deliveries_endpoint_created_idx');

            // Cross-tenant on purpose. See the class docblock.
            $table->index(['status', 'next_attempt_at'], 'wh_deliveries_retry_idx');

            $table->unique(['webhook_endpoint_id', 'event_id'], 'wh_deliveries_event_id_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
