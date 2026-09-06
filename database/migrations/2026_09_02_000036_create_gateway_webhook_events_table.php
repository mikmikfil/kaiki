<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inbound webhook idempotency (`docs/data-model.md` §2.7, §6 item 36).
 *
 * ## Why a `payments` row is not enough
 *
 * §2.7 lists the four reasons and each is a different failure:
 *
 * 1. A webhook can arrive **for an unknown reference** — a payment created by a
 *    process that then crashed, or a test event from a gateway dashboard.
 * 2. It can arrive **twice**. Both gateways retry until they get a 2xx, and a
 *    slow response is indistinguishable from a lost one.
 * 3. It can arrive **before we finished creating the payment**. Stripe is fast
 *    enough that this genuinely happens.
 * 4. It can arrive **for a tenant we have not resolved yet** — which is why
 *    `tenant_id` is nullable here and nowhere else.
 *
 * This row is written **first, in its own transaction, before any money logic**,
 * and the endpoint answers 2xx as soon as it exists (brief §3). Everything else
 * happens in a queued job keyed on this row's id.
 *
 * ## The only table in the schema that is not tenant-owned at write time
 *
 * `vat_rates` is platform-owned and stays that way. This one **acquires** a
 * tenant later, once the payment is matched, which is a third case — and the
 * reason `tenant_id` is nullable rather than absent.
 *
 * ## `gw_events_provider_event_uq` is the idempotency guarantee
 *
 * A duplicate delivery violates it, and that violation is the *answer*: respond
 * 200, do nothing. Checking for an existing row first would leave a window
 * between the check and the insert, which is precisely the race two concurrent
 * retries produce.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_webhook_events', function (Blueprint $table): void {
            $table->id();

            // **Nullable, and the only one in the schema.** The row is written
            // before the tenant is known; it is backfilled when the payment is
            // matched. `nullOnDelete` rather than cascade: a deleted tenant must
            // not take the evidence of an inbound webhook with it.
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();

            $table->string('provider', 24);
            // The provider's own event id. Long, because Stripe's are.
            $table->string('event_id', 190);
            $table->string('event_type', 64)->nullable();

            // **Recorded even when false**, for abuse investigation (PAY-7). A
            // stream of unverified webhooks from one address is a thing an
            // operator's platform should be able to see.
            $table->boolean('signature_valid')->default(false);

            // **encrypted:array** — a gateway payload carries a cardholder name,
            // the last four digits and whatever else the provider included.
            // Same posture as `payments.raw_payload`.
            $table->text('payload');

            $table->string('status', 16)->default('received');
            // `nullOnDelete`: a payment removed by a data-fix must not delete
            // the record that it was paid for.
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('error_message', 500)->nullable();

            $table->timestamp('processed_at')->nullable();
            // Separate from `created_at`: when the *gateway* says it sent it,
            // against when we wrote the row. A gap between the two is a queue
            // or a network problem worth being able to see.
            $table->timestamp('received_at');

            $table->timestamps();

            // The idempotency guarantee. A duplicate delivery hits this and the
            // endpoint returns 200 immediately.
            $table->unique(['provider', 'event_id'], 'gw_events_provider_event_uq');
            // The failure feed, cross-tenant because it is a platform view.
            $table->index(['status', 'received_at'], 'gw_events_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_webhook_events');
    }
};
