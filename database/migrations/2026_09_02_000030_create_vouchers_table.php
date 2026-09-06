<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator-issued credit (`docs/data-model.md` §2.5).
 *
 * ## Item 30, and it must come before `bookings`
 *
 * §6 is explicit and the reason is structural: `bookings.voucher_id` is a real
 * foreign key, and SQLite cannot add one to a table that already exists (§0).
 * So the two tables are a cycle that has to be broken on one side, and this is
 * the side that gets created first.
 *
 * `issued_for_booking_id` is the FK-less half of that cycle: a plain indexed
 * `unsignedBigInteger` pointing at a table that does not exist yet. Integrity
 * is the application's job and the nightly reconciler's — permanently, not
 * until somebody gets round to it. The same permanent accommodation
 * `vessel_blocks.booking_id` already carries.
 *
 * ## `remaining_cents` is derived and locked
 *
 * §1.9 and the **[LOCK]** marker in §2.5. It is recomputed from
 * `voucher_redemptions` inside a transaction with `lockForUpdate()` on this
 * row, never decremented blindly — two concurrent checkouts could otherwise
 * both consume the last €50, which is the overselling bug wearing different
 * clothes. The ledger arrives with confirmation; the column arrives here
 * because the table does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique('vouchers_uuid_unique');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Uppercase, ambiguity-free alphabet, unique per tenant. `/v/{code}`
            // — but the code is not a bearer token: redeeming one is an
            // authenticated operator action or a checkout that also knows the
            // booking, so guessing a code alone buys nothing.
            $table->string('code', 24);

            $table->unsignedInteger('amount_cents');
            // Derived (§1.9) and locked. Never decremented blindly.
            $table->unsignedInteger('remaining_cents');
            $table->char('currency', 3)->default('EUR');
            $table->string('status', 16)->default('active');

            $table->timestamp('issued_at');
            // Null means never expires, which is a real operator choice and not
            // a missing value.
            $table->timestamp('expires_at')->nullable();

            // **No foreign key**, permanently. See the class docblock: this is
            // the weak side of the cycle with `bookings`, and SQLite cannot add
            // the constraint later even if we changed our minds.
            $table->unsignedBigInteger('issued_for_booking_id')->nullable();

            $table->string('reason', 32)->default('goodwill');
            $table->string('notes', 500)->nullable();

            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // One reminder, thirty days out. A column rather than a log line so
            // the scheduler can ask "have I already told them" in the query.
            $table->timestamp('expiry_reminder_sent_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code'], 'vouchers_tenant_code_uq');
            $table->index(['tenant_id', 'status', 'expires_at'], 'vouchers_tenant_status_expires_idx');
            $table->index(['tenant_id', 'issued_for_booking_id'], 'vouchers_issued_for_booking_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
