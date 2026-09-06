<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The voucher ledger (`docs/data-model.md` §2.5, §6 item 34, ADR-0017).
 *
 * `vouchers.remaining_cents` is a denormalised number and PRC-19.4 requires it
 * to be **reconstructible from these rows at all times**. A reconciliation test
 * asserts it, because a balance that can only be believed is a balance that
 * eventually is not true.
 *
 * ## PRC-19.4 asks for a "reversal row"; §2.5 makes that impossible
 *
 * The requirement describes a ledger of movements each carrying a direction,
 * and says cancellation *"writes a reversal row and never deletes a redemption
 * row"*. But §2.5 specifies `voucher_redemptions_v_b_uq` — **unique** on
 * (`tenant_id`, `voucher_id`, `booking_id`) — which permits exactly one row per
 * voucher per booking and therefore forbids a second, opposite one.
 *
 * The data model is authoritative on schema (`CLAUDE.md`), and its shape is the
 * better one here: the unique index is what makes applying a voucher twice to
 * the same booking impossible, which is a real double-spend and worth more than
 * the symmetry of two rows. So a reversal is `reversed_at` and
 * `reversed_amount_cents` **on the row being reversed**, and the ledger's
 * arithmetic is `Σ(amount_cents − reversed_amount_cents)`.
 *
 * Nothing is lost: the movement, its direction, its amount and its time are all
 * still recorded, still per booking, and still never deleted. `docs/spec.md`
 * PRC-19.4 is reconciled to say so, with the reason in `CHANGELOG.md`.
 *
 * ## `reason` is added here
 *
 * PRC-19.4 lists it among the things a movement carries and §2.5 omits it. It
 * is the difference between "the guest cancelled" and "the operator overrode
 * the policy under §5.9 with a recorded reason", which is a question an
 * accountant asks in aggregate and a free-text note cannot answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voucher_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // `restrictOnDelete` on both: a voucher with redemptions cannot be
            // force-deleted, and neither can the booking that spent it. Soft
            // delete is the only route, because these two rows are money.
            $table->foreignId('voucher_id')->constrained('vouchers')->restrictOnDelete();
            $table->foreignId('booking_id')->constrained('bookings')->restrictOnDelete();

            // Positive, always. §1.4 puts the sign in the column's meaning:
            // `amount_cents` is what was taken off the voucher and
            // `reversed_amount_cents` is what went back.
            $table->unsignedInteger('amount_cents');
            $table->timestamp('redeemed_at');

            $table->timestamp('reversed_at')->nullable();
            $table->unsignedInteger('reversed_amount_cents')->default(0);

            // Why the movement happened (PRC-19.4). Nullable because an
            // ordinary redemption at checkout has no reason worth recording —
            // the guest spent their voucher, which is what vouchers are for.
            $table->string('reason', 32)->nullable();

            // **Never deleted**, and no soft-delete column either: a
            // `deleted_at` would be an invitation, and this table's whole value
            // is that it is append-and-amend only.
            $table->timestamps();

            $table->unique(['tenant_id', 'voucher_id', 'booking_id'], 'voucher_redemptions_v_b_uq');
            $table->index(['tenant_id', 'voucher_id'], 'voucher_redemptions_tenant_voucher_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_redemptions');
    }
};
