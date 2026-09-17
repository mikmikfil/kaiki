<?php

declare(strict_types=1);

use App\Models\DiscountCode;
use App\Models\Voucher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Discount codes, «κουπόνια» (product owner, 2026-09-17).
 *
 * ## A table of their own, not `vouchers`
 *
 * {@see Voucher} is **credit owed to one guest**: an amount, a ledger of what
 * was spent, a remaining balance recomputed under a lock, an expiry reminder,
 * a reason it was issued. A discount code is **a marketing rule** anybody may
 * type: «SUMMER10» for 10% off, «FAMILY» for 20 € off, used fifty times, with
 * no balance at all. Squeezing the second into the first would mean a
 * percentage that has no `amount_cents`, a `remaining_cents` that means
 * nothing, and a redemption ledger written for a rule that owes no one — every
 * voucher invariant bent to fit. {@see DiscountCode} has the argument in full.
 *
 * ## What is on a code
 *
 * `name` is the operator's own «Εσωτερικό όνομα» — «Newsletter Ιουνίου», never
 * shown to a guest — so the statistics say which campaign brought the money
 * rather than which string of capitals. The `code` is what the guest types,
 * stored upper-case and unique per operator.
 *
 * ## On the booking
 *
 * `bookings.discount_code_id`, nullable and **without a foreign key**
 * (`docs/data-model.md` §6: nothing new constrains an existing table). A code
 * deleted later leaves the number on the booking and the booking's frozen
 * snapshot still says what was taken off and why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discount_codes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('name', 120);
            $table->string('code', 32);

            // `App\Enums\DiscountKind`: `percent` (value 1–100) or `fixed`
            // (value in cents).
            $table->string('kind', 16);
            $table->unsignedInteger('value');

            // Inclusive days, in the operator's timezone. Null is open-ended.
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();

            // Null is unlimited.
            $table->unsignedInteger('max_uses')->nullable();

            // Null means every trip.
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code'], 'discount_codes_code_uq');
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->unsignedBigInteger('discount_code_id')->nullable();
            $table->index('discount_code_id', 'bookings_discount_code_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex('bookings_discount_code_idx');
            $table->dropColumn('discount_code_id');
        });

        Schema::dropIfExists('discount_codes');
    }
};
