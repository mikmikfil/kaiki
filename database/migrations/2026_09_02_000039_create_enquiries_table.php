<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Ask a question" (`docs/data-model.md` §2.5, §6 item 39, spec BKG-28 FIXED).
 *
 * ## It is not a booking, and it never touches availability
 *
 * BKG-28 is FIXED and the second half is the load-bearing part: *"It is not a
 * booking and never touches availability."* No hold, no counter, no departure
 * lookup, no `seats_held`. A test counts the queries against the availability
 * tables and asserts zero, because the natural implementation — "well, we know
 * the product and the date, so let us check" — is one helpful line away and
 * would put the most spam-exposed endpoint in the system on the hot path of the
 * engine.
 *
 * ## The honeypot is never a column
 *
 * §2.5: *"a honeypot field that is never persisted."* There is deliberately no
 * `company_website` here, and a test asserts the column does not exist —
 * because a stored honeypot value is a column nobody remembers the purpose of,
 * and in three years somebody renders it on a screen.
 *
 * ## `ip_address` is here and `email` is indexed, for two different reasons
 *
 * The address is rate limiting and spam review (§2.5) — a forged stream from
 * one address is invisible without it, the same argument PAY-7 makes for
 * webhooks. The email index is **GDPR subject lookup**: an erasure request
 * arrives with an address and nothing else, and a full scan of every operator's
 * enquiries is not an answer.
 *
 * ## Hard delete, no `deleted_at`
 *
 * §2.5. The GDPR purge removes old enquiries outright, and `status = spam` rows
 * go after thirty days. A soft-deleted personal record is a personal record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enquiries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Null for a general enquiry — "do you do sunset trips?" is not
            // about a product, and refusing it would push the guest to email.
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();

            $table->string('name', 120);
            $table->string('email', 190);
            $table->string('phone', 32)->nullable();

            // A **local** date with no timezone semantics (§1.5): the guest
            // means "the fourth of July", not an instant.
            $table->date('preferred_date')->nullable();
            $table->unsignedSmallInteger('pax')->nullable();

            // Stored and shown to the operator verbatim, never translated
            // (`docs/api.md` §4). It is the guest's own words.
            $table->text('message');
            $table->char('locale', 2)->default('el');

            /* `new` | `in_progress` | `answered` | `converted` | `spam` | `closed`. */
            $table->string('status', 16)->default('new');

            $table->foreignId('converted_booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->timestamp('answered_at')->nullable();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('source', 16)->default('widget');

            // IPv6-safe length, as everywhere else in the schema.
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();

            $table->timestamps();

            // The operator's inbox, in the order they read it.
            $table->index(['tenant_id', 'status', 'created_at'], 'enquiries_tenant_status_created_idx');
            $table->index(['tenant_id', 'product_id'], 'enquiries_tenant_product_idx');
            // GDPR subject lookup — see the class docblock.
            $table->index(['tenant_id', 'email'], 'enquiries_tenant_email_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enquiries');
    }
};
