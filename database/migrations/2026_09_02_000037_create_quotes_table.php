<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator-built quotes (`docs/data-model.md` §2.5, §4.4, §6 item 37).
 *
 * ## A quote is not a hold, and the schema says so by omission
 *
 * §4.4's own note: *"the boat is not reserved while the guest thinks about
 * it."* There is no `holds_until` here and no counter this table touches.
 * BKG-25 allows the operator to opt into holding the window when they send —
 * and that hold is a `vessel_blocks` row with a **visible expiry equal to
 * `valid_until`**, because a quote request that blocked a boat indefinitely
 * would be the most expensive feature in the product.
 *
 * ## Revisions are new rows, never an edit
 *
 * §4.4's transition table is explicit: `sent → revise` *"creates a **new** row
 * at `version + 1` and supersedes this one; the row itself never returns to
 * `draft` in place"*. The unique index on (`tenant_id`, `booking_id`,
 * `version`) is what makes that a property of the schema rather than of
 * somebody's discipline, and it is why the superseded row goes to `expired`
 * rather than being deleted: **the guest may still have the old link open**,
 * and `/q/{token}` has to be able to say "this quote was replaced".
 *
 * ## `quote_token` is globally unique, like every other guest token
 *
 * `/q/{token}` carries no tenant, so the token *is* the tenant resolution. Same
 * reasoning as `bookings.manage_token` and `guest_details_token`, and the same
 * shape: char(40), unique across the whole table with no tenant prefix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Cascade: a quote has no meaning without its booking, and the
            // booking is soft-deleted, so this only ever fires on a real purge.
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();

            /*
             * Increments per revision (§4.4). Never reused, and the unique
             * index below is what stops two operators building version 2 of the
             * same quote at once.
             */
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status', 16)->default('draft');

            // **Global** unique, no tenant prefix: the URL carries no tenant, so
            // the token is what resolves one.
            $table->char('quote_token', 40)->unique();

            $table->unsignedInteger('subtotal_cents')->default(0);
            $table->unsignedInteger('discount_cents')->default(0);
            $table->unsignedInteger('total_cents')->default(0);
            $table->unsignedInteger('deposit_cents')->default(0);
            $table->unsignedSmallInteger('vat_rate_bp');

            // UTC. The expiry sweeper reads this through
            // `quotes_tenant_status_valid_idx`.
            $table->timestamp('valid_until');

            // The operator's covering note, in the **guest's** locale — not the
            // operator's. A quote is a document the guest reads.
            $table->text('message')->nullable();
            $table->text('terms')->nullable();

            $table->timestamp('sent_at')->nullable();
            // First time `/q/{token}` was opened. An operator chasing a quote
            // wants to know whether it was ever looked at.
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->string('decline_reason', 500)->nullable();
            $table->timestamp('expired_at')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // No `deleted_at`. §2.5: hard delete only via the booking cascade —
            // a quote is part of the record of what was offered, and an offer
            // that can be quietly withdrawn from the trail is not a record.
            $table->timestamps();

            $table->unique(['tenant_id', 'booking_id', 'version'], 'quotes_tenant_booking_version_uq');

            // The expiry sweeper and the "pending quotes" dashboard card read
            // the same three columns in the same order.
            $table->index(['tenant_id', 'status', 'valid_until'], 'quotes_tenant_status_valid_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
