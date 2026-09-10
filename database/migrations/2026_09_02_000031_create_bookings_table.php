<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The aggregate root (`docs/data-model.md` §2.5, §6 item 31).
 *
 * The largest table in the schema, and the one every other M2 table points at.
 * It carries five things that are unusual enough to be worth naming here,
 * because each is a decision somebody would otherwise reverse:
 *
 * 1. **The draft row *is* the hold** (brief §5.4, ADR-0005). `hold_expires_at`
 *    plus `status` is the durable record; the cache lock is a mutex around the
 *    write and nothing more. A Redis restart never releases a hold, because the
 *    hold was never in Redis.
 *
 * 2. **`bookings_hold_expiry_idx` deliberately does not lead with `tenant_id`.**
 *    The sweeper is a platform job running across every operator at once, and a
 *    tenant-first index would make it a full scan. §2.5 flags it as the only
 *    such index in this table.
 *
 * 3. **Four `[SNAP]` columns.** `pax_breakdown`, `extras_snapshot`,
 *    `policy_snapshot` and `price_snapshot` are frozen at first persistence and
 *    never recomputed. A refund computed from the *current* cancellation policy
 *    is the failure `CLAUDE.md` names outright.
 *
 * 4. **`reference` is `varchar(16)`, not ADR-0007's `char(9)`.** The ADR's own
 *    collision strategy widens the random part from five characters to six
 *    after five failed attempts, which `char(9)` cannot hold — so the ADR
 *    contradicts itself and `docs/data-model.md`, which is authoritative on
 *    schema (`CLAUDE.md`), says 16. The extra bytes also leave room for the
 *    configurable prefix that BKG-3 item 1 requires.
 *
 * 5. **`terms_accepted_at` is new here.** BKG-7 requires explicit consent to
 *    the operator's terms and privacy policy with a stored timestamp, and GDR-9
 *    says it lives on the booking — but §2.5 listed only `ip_address`, which is
 *    the *evidence* and not the *fact*. Added with §2.5 updated to match.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique('bookings_uuid_unique');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // `KAI-7F3K2`. See the docblock for why this is not char(9).
            $table->string('reference', 16);

            // `restrictOnDelete` throughout: a booking must never lose its
            // subject. An operator who wants a product gone soft-deletes it.
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('vessel_id')->nullable()->constrained('vessels')->restrictOnDelete();
            $table->foreignId('departure_id')->nullable()->constrained('departures')->restrictOnDelete();

            // Snapshot of `products.mode` at creation. An operator switching a
            // product from per_seat to per_vessel must not reinterpret bookings
            // already taken — which is also why `SaveProduct` refuses the change
            // once one exists.
            $table->string('mode', 16);
            $table->string('status', 24)->default('draft');
            $table->string('source', 16);
            // Drives every email, SMS, PDF and token page for this booking, for
            // its whole life. Not the operator's locale and not the request's.
            $table->char('locale', 2)->default('el');

            // §1.5: the local trio plus the UTC pair, always written together.
            $table->date('local_date');
            $table->time('local_time');
            $table->timestamp('starts_at_utc');
            $table->timestamp('ends_at_utc');

            // Nullable, because a draft is a hold on seats before it is a
            // booking by a person (ADR-0030). The widget asks for a date and a
            // party and nothing else; the lead guest is typed on the checkout
            // page, and `StartCheckout` refuses a booking that still has none —
            // so no booking reaches a gateway, an invoice or a manifest without
            // one. `NOT NULL` here would only have moved the question earlier,
            // into a form nobody wanted to fill in before seeing a price.
            $table->string('guest_name', 120)->nullable();
            $table->string('guest_email', 190)->nullable();
            // E.164 where we could normalise it. BKG-8: a malformed phone blocks
            // SMS and must never block the booking, so this is nullable.
            $table->string('guest_phone', 32)->nullable();
            $table->char('guest_nationality', 2)->nullable();
            $table->char('guest_country', 2)->nullable();
            // Present ⇒ the invoice is a ΤΠΥ rather than an ΑΛΠ (§10).
            $table->string('guest_vat_number', 20)->nullable();
            $table->string('guest_company_name', 180)->nullable();

            // Both derived, and they are **not** the same number. `pax_total`
            // counts every person for the manifest; `pax_capacity_total` counts
            // only capacity-occupying bands and is what decrements a departure.
            // A party of two adults and two infants is four and two.
            $table->unsignedSmallInteger('pax_total')->default(0);
            $table->unsignedSmallInteger('pax_capacity_total')->default(0);

            // The four [SNAP] columns. No JSON defaults: MySQL 8 refuses a
            // literal default on a JSON column, so the defaults live on the
            // model and these are plain NOT NULL / nullable.
            $table->json('pax_breakdown');
            $table->json('extras_snapshot');
            $table->json('policy_snapshot')->nullable();
            $table->json('price_snapshot')->nullable();

            $table->unsignedInteger('subtotal_cents')->default(0);
            $table->unsignedInteger('extras_cents')->default(0);
            // Positive; §1.4 puts the sign in the column's meaning rather than
            // in its value, so no money column is ever negative.
            $table->unsignedInteger('discount_cents')->default(0);
            $table->unsignedInteger('total_cents')->default(0);
            // 0 means pay in full, which is a real configuration and not a null.
            $table->unsignedInteger('deposit_cents')->default(0);
            $table->unsignedInteger('paid_cents')->default(0);
            $table->unsignedInteger('balance_cents')->default(0);
            $table->unsignedInteger('refunded_cents')->default(0);

            // [SNAP], both resolved from `vat_rates` at pricing time and frozen.
            // `vat_category` is stored rather than derived so the myDATA client
            // never maps a percentage to a category in PHP (CAT-11a).
            $table->unsignedSmallInteger('vat_rate_bp');
            $table->string('vat_category', 16);
            $table->unsignedInteger('vat_cents')->default(0);

            // The *primary* voucher, a convenience for the common one-voucher
            // case and for Filament. `voucher_redemptions` is the authoritative
            // money record — never compute a balance from this column.
            $table->foreignId('voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();

            $table->string('guest_details_status', 16)->default('not_required');
            $table->timestamp('guest_details_deadline_at')->nullable();
            // Minted lazily, when details are first requested, so an unused
            // booking never leaves a live URL lying about. Globally unique: the
            // path carries no tenant.
            $table->char('guest_details_token', 40)->nullable()->unique('bookings_guest_details_token_unique');
            $table->char('manage_token', 40)->unique('bookings_manage_token_unique');

            // The hold (ADR-0005). Non-null only while draft or pending_payment.
            $table->timestamp('hold_expires_at')->nullable();

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_by', 16)->nullable();
            $table->string('cancel_reason', 32)->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->boolean('no_show')->default(false);

            $table->text('special_requests')->nullable();
            // Operator-only. Nothing guest-facing may ever render this, and the
            // guest pages in #86 have to be written knowing that.
            $table->text('internal_notes')->nullable();
            $table->boolean('is_test')->default(false);

            $table->string('utm_source', 120)->nullable();
            $table->string('utm_medium', 120)->nullable();
            $table->string('utm_campaign', 120)->nullable();
            $table->string('utm_term', 120)->nullable();
            $table->string('utm_content', 120)->nullable();
            $table->string('referrer_url', 500)->nullable();

            // GDR-9 / BKG-7: consent is a fact with a time, and the address is
            // its evidence. 45 characters is IPv6-safe.
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'reference'], 'bookings_tenant_reference_uq');
            $table->index(['tenant_id', 'departure_id', 'status'], 'bookings_tenant_departure_status_idx');
            $table->index(['tenant_id', 'status', 'starts_at_utc'], 'bookings_tenant_status_starts_idx');
            // **Not tenant-first**, on purpose — the hold sweeper is a platform
            // job. The only index in this table that leads with something else.
            $table->index(['status', 'hold_expires_at'], 'bookings_hold_expiry_idx');
            $table->index(['tenant_id', 'vessel_id', 'starts_at_utc', 'ends_at_utc'], 'bookings_vessel_window_idx');
            $table->index(['tenant_id', 'guest_email'], 'bookings_tenant_email_idx');
            $table->index(['tenant_id', 'guest_details_status', 'guest_details_deadline_at'], 'bookings_tenant_guest_details_idx');
            $table->index(['tenant_id', 'created_at'], 'bookings_tenant_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
