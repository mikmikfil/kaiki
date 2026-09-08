<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The myDATA document (spec MYD-1…MYD-16, `docs/data-model.md` §4.6 `invoices`).
 *
 * One booking may carry several: an ΑΛΠ, and later a credit note undoing it.
 *
 * ## `number` is nullable, and the data model's column table is wrong about it
 *
 * §4.6's grid says `number` is `no` null; its own **[LOCK]** note two paragraphs
 * later says *"`number` is nullable until allocated"* and explains why at
 * length. The note wins, because it is the later correction and because the
 * rule it protects is the point of the design: **MYD-4.2 allocates a number at
 * the send attempt, never at row creation**, so a document that is written and
 * never submitted does not burn one. A non-null column forces a number onto
 * every `pending` row and defeats that entirely.
 *
 * `docs/data-model.md` is amended in this commit to match.
 *
 * ## The uniqueness is the database's job, not the allocator's
 *
 * `unique(tenant_id, series, year, number)` is the real guarantee of legal
 * sequence integrity. The `lockForUpdate()` on `series_counters` is what makes
 * collisions rare; this index is what makes them impossible, and the allocator
 * retries on violation rather than trusting its own lock. On SQLite the lock is
 * a no-op (§0), so on a developer's machine the index is the *only* guarantee —
 * which is a good reason for it to be the one that actually holds.
 *
 * ## `retries_idx` is deliberately not tenant-first
 *
 * The retry sweeper runs across every tenant at once, the same shape as
 * `wh_deliveries_retry_idx`. An index starting with `tenant_id` would force it
 * to scan per tenant, which is the wrong shape for the one query that reads it.
 *
 * ## Payloads are stored, and MYD-15 is why they are not encrypted
 *
 * `request_payload` and `response_payload` hold what went to AADE and what came
 * back, redacted, so support can answer "what did we actually send". There is no
 * card data in a myDATA document — it carries amounts, a VAT category and
 * possibly a counterparty ΑΦΜ — so §1.7's encryption rule does not reach them.
 * **Credentials never appear in either**, which is the client's job rather than
 * the schema's, and is asserted against the stored bytes.
 *
 * ## Never deleted
 *
 * §1.4 puts `invoices` among the rows no code path removes. `booking_id` is
 * `restrictOnDelete` for that reason: a booking with an invoice cannot be
 * deleted out from under a document that exists in a tax register.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();

            $table->string('type', 16);
            $table->unsignedBigInteger('cancels_invoice_id')->nullable();

            $table->string('series', 16);
            // Nullable until allocated. See the class docblock.
            $table->unsignedInteger('number')->nullable();
            $table->unsignedSmallInteger('year');

            $table->timestamp('issued_at')->nullable();
            $table->string('mark', 40)->nullable();
            $table->string('uid', 64)->nullable();
            $table->string('authentication_code', 120)->nullable();
            $table->string('qr_url', 500)->nullable();

            $table->unsignedInteger('net_cents');
            $table->unsignedInteger('vat_cents');
            $table->unsignedInteger('total_cents');
            $table->unsignedSmallInteger('vat_rate_bp');
            $table->string('vat_category', 16)->nullable();
            $table->string('income_classification', 16)->nullable();

            $table->string('counterparty_vat', 20)->nullable();
            $table->string('counterparty_name', 180)->nullable();
            $table->char('counterparty_country', 2)->nullable();

            $table->string('status', 16)->default('pending');
            $table->string('last_error_code', 16)->nullable();
            $table->text('last_error_message')->nullable();
            $table->string('last_error_message_el', 500)->nullable();
            $table->unsignedTinyInteger('retries')->default(0);
            $table->timestamp('next_retry_at')->nullable();

            $table->text('request_payload')->nullable();
            $table->text('response_payload')->nullable();
            $table->string('pdf_path', 255)->nullable();

            // MYD-11: which AADE endpoint issued this, chosen per environment
            // and stored so the panel can tell an operator they are in test mode
            // — and so a live document is never mistaken for a sandbox one after
            // the fact.
            $table->string('environment', 8)->default('live');

            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['tenant_id', 'series', 'year', 'number'], 'invoices_tenant_series_num_uq');
            $table->index(['tenant_id', 'booking_id'], 'invoices_tenant_booking_idx');
            $table->index(['status', 'next_retry_at'], 'invoices_retry_idx');
            $table->index(['tenant_id', 'status', 'created_at'], 'invoices_tenant_status_idx');
            $table->index(['tenant_id', 'mark'], 'invoices_mark_idx');

            // Self-reference, declared inside the same `create` so SQLite can
            // carry it — §0 forbids adding a foreign key in a later migration.
            $table->foreign('cancels_invoice_id')->references('id')->on('invoices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
