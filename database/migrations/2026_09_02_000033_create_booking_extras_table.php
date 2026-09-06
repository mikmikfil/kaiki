<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extras as rows (`docs/data-model.md` §2.5, §6 item 33).
 *
 * ## Why both these rows and `bookings.extras_snapshot` exist
 *
 * They answer different questions and neither can answer the other's. The rows
 * are **queryable** — "how many snorkel kits did we sell in July", and the
 * operator's to-do list of unfulfilled on-request items. The JSON is
 * **frozen** — it renders the guest's confirmation email a year later even
 * after every `extras` row has been deleted.
 *
 * They are written in the same transaction and must agree; the nightly
 * reconciler compares them. That is deliberate duplication with a checker, not
 * duplication nobody noticed.
 *
 * ## `is_on_request` extras are excluded from the total
 *
 * They have no price until the operator confirms one, so `unit_price_cents` is
 * 0 and they contribute nothing to `total_cents`. Including them at zero and
 * "fixing it up later" is how a guest is charged for something they were shown
 * as free.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_extras', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            // `nullOnDelete`, unlike the booking's own subject FKs: an extra
            // being retired must not lock the operator out of deleting it, and
            // `extra_name` below is why nothing is lost when they do.
            $table->foreignId('extra_id')->nullable()->constrained('extras')->nullOnDelete();

            // Translatable snapshot of the name at booking time. A JSON column
            // for the same reason every other translatable field is one
            // (ADR-0008) — and a snapshot because the guest bought the thing
            // that was called this.
            $table->json('extra_name');
            $table->string('pricing_type', 16);

            $table->unsignedSmallInteger('qty')->default(1);
            $table->unsignedInteger('unit_price_cents')->default(0);
            $table->unsignedInteger('total_cents')->default(0);

            $table->boolean('is_on_request')->default(false);
            $table->timestamp('fulfilled_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'booking_id'], 'booking_extras_tenant_booking_idx');
            $table->index(['tenant_id', 'extra_id'], 'booking_extras_tenant_extra_idx');
            $table->index(['tenant_id', 'is_on_request', 'fulfilled_at'], 'booking_extras_on_request_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_extras');
    }
};
