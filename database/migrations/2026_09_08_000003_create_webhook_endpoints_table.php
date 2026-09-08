<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an operator's own systems are told things happened (spec OPS-19, OPS-20).
 *
 * Item 41 of `docs/data-model.md` §6, specified there since M0 and built here.
 *
 * ## The secret is encrypted and shown once, like an API key
 *
 * `signing_secret` is the whole of the receiver's security: it is what lets
 * somebody's accounting script believe a POST claiming a booking was confirmed.
 * So it is `text` and cast `encrypted` (§1.7), and the panel reveals it exactly
 * once at creation — the same shape `api_keys` uses, for the same reason, and
 * `ApiKeyResource` is the screen this one copies.
 *
 * Unlike an API key it is **not** hashed, and that is forced rather than
 * chosen: signing is symmetric, so the platform has to be able to read the
 * secret back on every delivery. Encryption is the strongest available answer,
 * and it is why rotation exists — §8.3 publishes two signatures for 24 hours so
 * an operator can roll one without dropping a delivery.
 *
 * ## `consecutive_failures` is a counter, not a log
 *
 * The log is `webhook_deliveries`. This is the number the auto-disable rule
 * reads: twenty in a row and the endpoint is switched off with an email, because
 * nobody's queue should burn for a week on a URL whose owner has moved on. It
 * resets on the first success, so an endpoint that fails nineteen times and then
 * works has a clean slate — the rule is about a dead URL, not a flaky afternoon.
 *
 * ## Soft deletes, because a delivery history outlives its endpoint
 *
 * `webhook_deliveries` has a cascading foreign key. Hard-deleting an endpoint
 * would take its history with it, and "what did we send that morning" is a
 * question asked *after* somebody deletes the endpoint they think is at fault.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique('webhook_endpoints_uuid_unique');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // The operator's own name for it — "τιμολόγηση", "Zapier". Shown in
            // the failure feed, so it has to mean something to the person
            // reading it at seven in the morning.
            $table->string('name', 80);

            // 500 rather than 255: a webhook URL is frequently somebody's
            // automation platform with a long opaque path segment.
            $table->string('url', 500);

            $table->text('signing_secret');

            // The subscribed event names, validated against
            // `App\Domain\Webhooks\EventRegistry` on the way in and again on the
            // way out — §3.13, and the same rule `BlockSettings` follows: a JSON
            // column an operator writes into is normalised on read as well as on
            // write, so a row from an older shape renders rather than throws.
            $table->json('events');

            $table->boolean('is_active')->default(true);

            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestamp('disabled_at')->nullable();
            $table->timestamp('last_delivery_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_active'], 'wh_endpoints_tenant_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoints');
    }
};
