<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money that moved (`docs/data-model.md` §2.5, §6 item 35).
 *
 * **Never deleted, never soft-deleted, never mutated except `status` and the
 * derived columns.** Kaiki never touches guest money (brief §1) — these rows
 * mirror the operator's own gateway, and a row that disappeared would be a
 * payment the operator cannot reconcile against a statement.
 *
 * ## Two indexes that carry the whole safety story
 *
 * `payments_tenant_idem_uq` on (`tenant_id`, `idempotency_key`) is what makes a
 * retried job incapable of double-charging. The key is minted by us **before**
 * the gateway call and passed to the gateway where it supports one (Stripe);
 * where it does not, this index is the only dedupe there is.
 *
 * `payments_gateway_ref_idx` is **deliberately not tenant-first**, like
 * `integration_credentials.external_account_id` and `bookings_hold_expiry_idx`:
 * an inbound webhook identifies a payment before tenancy is resolved, and a
 * tenant-first index would make that lookup a full scan.
 *
 * ## `refunds_payment_id` is a self-reference
 *
 * A refund is a `Payment` row of `kind = refund` pointing at the charge it
 * reverses, rather than a negative amount on the original. Two reasons: every
 * money column in this schema is unsigned (§1.4, the sign lives in the meaning),
 * and a refund has its own gateway reference, its own timestamp and its own
 * failure mode — all of which need somewhere to live.
 *
 * ## `raw_payload` is encrypted
 *
 * A gateway response carries cardholder name, the last four digits, and
 * whatever else the provider felt like including. MYD-15 and SEC-9 say a
 * credential is never logged; this is the same posture for the response side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique('payments_uuid_unique');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // `restrictOnDelete`: a payment must never lose the booking it paid
            // for. Tax law outranks tidiness.
            $table->foreignId('booking_id')->constrained('bookings')->restrictOnDelete();

            // `cash` and `bank_transfer` are for manual bookings (BKG-33) and
            // never call anything — they are recorded, not taken.
            $table->string('gateway', 24);
            $table->string('kind', 16);

            // Always positive. `kind = refund` carries the direction (§1.4).
            $table->unsignedInteger('amount_cents');
            $table->char('currency', 3)->default('EUR');
            $table->string('status', 16)->default('pending');

            $table->string('gateway_ref', 190)->nullable();
            // The settled transaction, where the provider distinguishes it from
            // the order it settled.
            $table->string('gateway_transaction_ref', 190)->nullable();

            // Self-reference: a refund points at the charge it reverses.
            $table->foreignId('refunds_payment_id')->nullable()->constrained('payments')->nullOnDelete();

            $table->char('idempotency_key', 40);

            // Short-lived and deliberately not indexed — nothing looks a
            // payment up by the URL it redirected to, and the URL stops working
            // long before the row stops mattering.
            $table->string('checkout_url', 1000)->nullable();
            // **encrypted:array** — a gateway response is not ours to store in
            // the clear.
            $table->text('raw_payload')->nullable();

            $table->string('failure_code', 64)->nullable();
            // Both languages as columns rather than a lang key, because a
            // gateway failure is mapped through a per-gateway dictionary
            // (PAY-12) at the moment it happens and the mapping may change
            // afterwards. What the operator was told is part of the record.
            $table->string('failure_message_el', 500)->nullable();
            $table->string('failure_message_en', 500)->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();

            // Set for cash and bank transfer, so an operator can see who took
            // the money at the desk.
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key'], 'payments_tenant_idem_uq');
            // **Not tenant-first** — the webhook resolver has no tenant yet.
            $table->index(['gateway', 'gateway_ref'], 'payments_gateway_ref_idx');
            $table->index(['tenant_id', 'booking_id', 'status'], 'payments_tenant_booking_idx');
            $table->index(['tenant_id', 'status', 'created_at'], 'payments_tenant_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
